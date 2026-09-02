<?php

namespace Rushing\RequestLogs\Http\Middleware;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Services\RequestLogCollector;
use Rushing\RequestLogs\Services\RequestLogService;
use Rushing\RequestLogs\Services\RequestLogTracker;
use Rushing\RequestLogs\Support\BodyCapture;

class GuzzleRequestLogMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected RequestLogService $requestLogService,
        protected RequestLogCollector $requestLogCollector
    ) {}

    /**
     * Guzzle middleware handler.
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            // Skip if not configured to log this request.
            if (! $this->shouldLogRequest($request, $options)) {
                return $handler($request, $options);
            }

            // Generate or use the existing correlation ID.
            $correlationHeaderName = config('request-logs.correlation.header_name', 'X-Request-Id');
            $ensureHeader = config('request-logs.correlation.ensure_header', true);
            $requestId = $request->getHeaderLine($correlationHeaderName);

            // If the header is missing and ensure_header is false, skip logging.
            if (! $requestId && ! $ensureHeader) {
                return $handler($request, $options);
            }

            // Auto-generate if missing and ensure_header is true.
            if (! $requestId) {
                $requestId = (string) Str::orderedUuid();
                $request = $request->withHeader($correlationHeaderName, $requestId);
            }

            // Start building the RequestLogData.
            $requestLogData = $this->startRequestLogData($request, $requestId);

            // Process the request and handle the response/error.
            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($requestLogData) {
                    // Complete the RequestLogData with response data.
                    $this->completeRequestLogData($requestLogData, $response);

                    // Defer storage until after the response is sent.
                    $this->deferLogStorage($requestLogData);

                    return $response;
                },
                function ($reason) use ($requestLogData) {
                    // Handle request/response errors.
                    $this->completeRequestLogDataWithError($requestLogData, $reason);

                    // Defer storage until after the response is sent.
                    $this->deferLogStorage($requestLogData);

                    throw $reason;
                }
            );
        };
    }

    /**
     * Start building the RequestLogData with request data.
     */
    protected function startRequestLogData(RequestInterface $request, string $requestId): RequestLogData
    {
        $requestData = [
            'headers' => $this->formatHeaders($request->getHeaders()),
            'body' => $this->getRequestBody($request),
            'ip_address' => gethostbyname(parse_url((string) $request->getUri(), PHP_URL_HOST) ?: 'localhost'),
            'user_agent' => $request->getHeaderLine('User-Agent') ?: 'Guzzle HTTP Client',
            'api_version' => $request->getHeaderLine('Accept-Version') ?: 'default',
            'timestamp' => now()->toIso8601String(),
        ];

        // Try to get the parent request ID from the current Laravel request context.
        $parentRequestId = $this->getParentRequestId();

        $meta = [
            'type' => 'outbound', // Mark as an outbound call.
            'client' => 'guzzle',
        ];

        // Add the parent_id if we have one.
        if ($parentRequestId) {
            $meta['parent_id'] = $parentRequestId;
        }

        return RequestLogData::instance([
            'id' => $requestId,
            'operationName' => $this->getOperationName($request),
            'url' => (string) $request->getUri(),
            'success' => false, // Updated in completeRequestLogData
            'statusCode' => 0, // Updated in completeRequestLogData
            'method' => $request->getMethod(),
            'request' => $requestData,
            'response' => ['body' => [], 'headers' => []], // Updated in completeRequestLogData
            'meta' => $meta,
        ]);
    }

    /**
     * Complete the RequestLogData with response data.
     */
    protected function completeRequestLogData(RequestLogData $requestLogData, ResponseInterface $response): void
    {
        $requestLogData->statusCode = $response->getStatusCode();
        $requestLogData->success = $response->getStatusCode() < 400;
        $requestLogData->response = [
            'headers' => $this->formatHeaders($response->getHeaders()),
            'body' => $this->getResponseBody($response),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Complete the RequestLogData with error information.
     */
    protected function completeRequestLogDataWithError(RequestLogData $requestLogData, $reason): void
    {
        $statusCode = 0;
        $responseBody = [];

        // Extract the status code from Guzzle exceptions.
        if ($reason instanceof RequestException && $reason->hasResponse()) {
            $response = $reason->getResponse();
            $statusCode = $response->getStatusCode();
            $responseBody = $this->getResponseBody($response);
        }

        $requestLogData->statusCode = $statusCode ?: 500;
        $requestLogData->success = false;
        $requestLogData->response = [
            'headers' => [],
            'body' => $responseBody ?: ['error' => $reason->getMessage()],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Defer log storage until after the response is sent.
     */
    protected function deferLogStorage(RequestLogData $requestLogData): void
    {
        // Use the collector to batch all request logs together.
        if (app()->bound('request')) {
            // Add to the collector — it registers a terminating callback once.
            $this->requestLogCollector->add($requestLogData);
        } else {
            // Fallback for CLI / non-HTTP contexts — store immediately.
            $requestLog = $this->storeRequestLog($requestLogData);
            $this->fireCompleteEvent($requestLogData, $requestLog);
        }
    }

    /**
     * Store the request log record in the database.
     */
    protected function storeRequestLog(RequestLogData $requestLogData): mixed
    {
        try {
            return $this->requestLogService->storeRequestLog($requestLogData->toRowArray());
        } catch (\Exception $e) {
            Log::error('Failed to store outbound request log: '.$e->getMessage(), [
                'exception' => $e,
                'request_id' => $requestLogData->id,
            ]);

            return null;
        }
    }

    /**
     * Fire the CompleteRequestLogEvent with the tracker and RequestLogData.
     */
    protected function fireCompleteEvent(RequestLogData $requestLogData, $requestLog): void
    {
        if (! $requestLog) {
            return;
        }

        // Outbound calls don't track models the same way, but we still fire the
        // event for consistency and extensibility.
        $tracker = app(RequestLogTracker::class);

        CompleteRequestLogEvent::dispatch(
            $requestLogData->id,
            $requestLog->id,
            $tracker,
            $requestLogData
        );
    }

    /**
     * Determine if the request should be logged.
     */
    protected function shouldLogRequest(RequestInterface $request, array $options): bool
    {
        // Check if outbound logging is enabled.
        if (! config('request-logs.outbound.enabled', true)) {
            return false;
        }

        // Check if this host should be excluded.
        $excludeHosts = config('request-logs.outbound.exclude_hosts', []);
        $host = parse_url((string) $request->getUri(), PHP_URL_HOST);

        foreach ($excludeHosts as $excludeHost) {
            if ($host && fnmatch($excludeHost, $host)) {
                return false;
            }
        }

        // Check if the request options disable logging.
        if (isset($options['request_logs_skip']) && $options['request_logs_skip']) {
            return false;
        }

        return true;
    }

    /**
     * Get the operation name from the request.
     */
    protected function getOperationName(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $host = $uri->getHost();

        return sprintf('%s %s%s', $request->getMethod(), $host ?: 'unknown', $path ?: '/');
    }

    /**
     * Format a headers array for consistent storage.
     */
    protected function formatHeaders(array $headers): array
    {
        return array_map(function ($values) {
            return implode(PHP_EOL, $values);
        }, $headers);
    }

    /**
     * Get the request body as an array or string.
     */
    protected function getRequestBody(RequestInterface $request): mixed
    {
        // Rewind after reading: casting a PSR-7 stream to string leaves the pointer at EOF,
        // so a client that reads the body after us (many SDKs do) would see an empty body.
        $stream = $request->getBody();

        // A non-seekable body can be read once, and the transport has not sent it yet.
        if (! $stream->isSeekable()) {
            return BodyCapture::describeStream($request->getHeaderLine('Content-Type'));
        }

        $body = (string) $stream;
        $stream->rewind();

        if (empty($body)) {
            return [];
        }

        // Non-text bodies are described, not captured — the log payload is queued and
        // json_encode throws on malformed UTF-8. See BodyCapture.
        return BodyCapture::capture($body, $request->getHeaderLine('Content-Type'));
    }

    /**
     * Get the response body as an array or string.
     */
    protected function getResponseBody(ResponseInterface $response): mixed
    {
        // Rewind after reading: casting a PSR-7 stream to string leaves the pointer at EOF,
        // so a client that reads the body after us (many SDKs do) would see an empty body.
        $stream = $response->getBody();

        // A non-seekable body (Guzzle `stream => true` — an SSE completion) can be read once, and the
        // caller has not read it yet. Describe it; reading it here hands the caller EOF.
        if (! $stream->isSeekable()) {
            return BodyCapture::describeStream($response->getHeaderLine('Content-Type'));
        }

        $body = (string) $stream;
        $stream->rewind();

        if (empty($body)) {
            return [];
        }

        // Non-text bodies are described, not captured — an outbound call that downloads
        // a file crashes the queue serializer identically to an inbound PDF export.
        return BodyCapture::capture($body, $response->getHeaderLine('Content-Type'));
    }

    /**
     * Get the parent request ID from the current Laravel request context.
     */
    protected function getParentRequestId(): ?string
    {
        try {
            if (app()->bound('request')) {
                $laravelRequest = app('request');

                // Check for RequestLogData set by the RequestLogMiddleware.
                if ($laravelRequest && $laravelRequest->attributes) {
                    $requestLogData = $laravelRequest->attributes->get('requestLogData');
                    if ($requestLogData && isset($requestLogData->id)) {
                        return $requestLogData->id;
                    }
                }

                // Fall back to the correlation header on the Laravel request.
                if ($laravelRequest && $laravelRequest->headers) {
                    $correlationHeaderName = config('request-logs.correlation.header_name', 'X-Request-Id');
                    $headerValue = $laravelRequest->headers->get($correlationHeaderName);
                    if ($headerValue) {
                        return $headerValue;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail if we can't get the parent request ID (e.g. CLI contexts).
        }

        return null;
    }
}
