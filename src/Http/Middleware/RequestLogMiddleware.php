<?php

namespace Rushing\RequestLogs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Services\RequestLogService;
use Rushing\RequestLogs\Services\RequestLogTracker;
use Symfony\Component\HttpFoundation\Response;

class RequestLogMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected RequestLogService $requestLogService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if not configured to log this request.
        if (! $this->shouldLogRequest($request)) {
            return $next($request);
        }

        // Generate or use the existing correlation ID.
        $correlationHeaderName = config('request-logs.correlation.header_name', 'X-Request-Id');
        $ensureHeader = config('request-logs.correlation.ensure_header', true);
        $requestId = $request->header($correlationHeaderName);

        // If the header is missing and ensure_header is false, skip logging.
        if (! $requestId && ! $ensureHeader) {
            return $next($request);
        }

        // Auto-generate if missing and ensure_header is true.
        if (! $requestId) {
            $requestId = (string) Str::orderedUuid();
        }

        $request->headers->set($correlationHeaderName, $requestId);

        // Start building the RequestLogData.
        $requestLogData = $this->startRequestLogData($request, $requestId);
        $request->attributes->set('requestLogData', $requestLogData);

        // Process the request.
        return $next($request);
    }

    /**
     * Handle the response after it has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldLogRequest($request)) {
            return;
        }

        $requestLogData = $request->attributes->get('requestLogData');
        if (! $requestLogData) {
            return;
        }

        // Complete the RequestLogData with response data.
        $this->completeRequestLogData($requestLogData, $response);

        // Store the request log record in the database.
        $requestLog = $this->storeRequestLog($requestLogData);

        // Fire the completion event with the tracker and RequestLogData.
        $this->fireCompleteEvent($requestLogData, $requestLog);
    }

    /**
     * Start building the RequestLogData with request data.
     */
    protected function startRequestLogData(Request $request, string $requestId): RequestLogData
    {
        $requestData = [
            'headers' => array_map(fn ($values) => implode(PHP_EOL, $values), $request->headers->all()),
            'body' => $request->all() ?: [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'api_version' => $request->header('Accept-Version', 'default'),
            'timestamp' => now()->toIso8601String(),
        ];

        return RequestLogData::instance([
            'id' => $requestId,
            'operationName' => $this->getOperationName($request),
            'url' => $request->fullUrl(),
            'success' => false, // Updated in completeRequestLogData
            'statusCode' => 0, // Updated in completeRequestLogData
            'method' => $request->method(),
            'request' => $requestData,
            'response' => ['body' => [], 'headers' => []], // Updated in completeRequestLogData
            'meta' => [], // Available for additional metadata
        ]);
    }

    /**
     * Complete the RequestLogData with response data.
     */
    public function completeRequestLogData(RequestLogData $requestLogData, Response $response): void
    {
        $requestLogData->statusCode = $response->getStatusCode();
        $requestLogData->success = $response->getStatusCode() < 400;
        $requestLogData->response = [
            'headers' => array_map(fn ($values) => implode(PHP_EOL, $values), $response->headers->all()),
            'body' => $this->getResponseBody($response),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Store the request log record in the database.
     */
    public function storeRequestLog(RequestLogData $requestLogData): mixed
    {
        try {
            $logData = [
                'request_id' => $requestLogData->id,
                'path' => parse_url($requestLogData->url, PHP_URL_PATH),
                'method' => $requestLogData->method,
                'api_version' => $requestLogData->request['api_version'] ?? 'default',
                'request_at' => $requestLogData->request['timestamp'],
                'response_at' => $requestLogData->response['timestamp'],
                'response_status' => $requestLogData->statusCode,
                'is_error' => ! $requestLogData->success,
            ];

            return $this->requestLogService->storeRequestLog($logData);
        } catch (\Exception $e) {
            Log::error('Failed to store request log: '.$e->getMessage(), [
                'exception' => $e,
                'request_id' => $requestLogData->id,
            ]);

            return null;
        }
    }

    /**
     * Fire the CompleteRequestLogEvent with the tracker instance and RequestLogData.
     */
    public function fireCompleteEvent(RequestLogData $requestLogData, $requestLog): void
    {
        if (! $requestLog) {
            return;
        }

        // Get the tracker instance with all tracked models for this request.
        $tracker = app(RequestLogTracker::class);

        // Fire the event with the RequestLogData and tracker instance.
        CompleteRequestLogEvent::dispatch(
            $requestLogData->id,
            $requestLog->id,
            $tracker, // Pass the tracker instance instead of a models array.
            $requestLogData
        );

        // Don't clear the tracker here — the listener handles it.
    }

    /**
     * Determine if the request should be logged.
     */
    protected function shouldLogRequest(Request $request): bool
    {
        // Check if request logging is enabled.
        if (! config('request-logs.enabled', true)) {
            return false;
        }

        // Skip OPTIONS requests (CORS preflight).
        if ($request->method() === 'OPTIONS') {
            return false;
        }

        // Check if the path should be excluded.
        $excludePaths = config('request-logs.exclude_paths', []);
        $path = $request->path();

        foreach ($excludePaths as $excludePath) {
            if (Str::is($excludePath, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the operation name from the request.
     */
    protected function getOperationName(Request $request): string
    {
        // Try the route name first.
        if ($request->route() && $request->route()->getName()) {
            return $request->route()->getName();
        }

        // Fall back to method + path.
        return $request->method().' '.$request->path();
    }

    /**
     * Get the response body as an array or string.
     */
    protected function getResponseBody(Response $response): mixed
    {
        $content = $response->getContent();

        // Try to decode a JSON response.
        if (str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
            $decoded = json_decode($content, true);

            return $decoded !== null ? $decoded : $content;
        }

        return $content;
    }
}
