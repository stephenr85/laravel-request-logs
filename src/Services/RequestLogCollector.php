<?php

namespace Rushing\RequestLogs\Services;

use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;

class RequestLogCollector
{
    /**
     * Request log data pending bulk storage.
     */
    protected array $pendingLogs = [];

    /**
     * Whether the terminating callback has been registered.
     */
    protected bool $terminatingCallbackRegistered = false;

    /**
     * Add request log data to the pending collection.
     */
    public function add(RequestLogData $requestLogData): void
    {
        $this->pendingLogs[] = $requestLogData;

        // Register the terminating callback only once.
        if (! $this->terminatingCallbackRegistered && app()->bound('request')) {
            $this->registerTerminatingCallback();
            $this->terminatingCallbackRegistered = true;
        }
    }

    /**
     * Get all pending request logs.
     */
    public function getPendingLogs(): array
    {
        return $this->pendingLogs;
    }

    /**
     * Clear all pending logs.
     */
    public function clear(): void
    {
        $this->pendingLogs = [];
    }

    /**
     * Register a terminating callback to store all logs in bulk.
     */
    protected function registerTerminatingCallback(): void
    {
        app()->terminating(function () {
            $this->flushLogs();
        });
    }

    /**
     * Flush all pending logs to the database.
     */
    public function flushLogs(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        $requestLogService = app(RequestLogService::class);
        $tracker = app(RequestLogTracker::class);

        // Prepare log data for bulk storage.
        $logDataArray = [];
        $requestLogDataMap = []; // Map request_id to RequestLogData for event firing.

        foreach ($this->pendingLogs as $requestLogData) {
            $logDataArray[] = [
                'request_id' => $requestLogData->id,
                'path' => parse_url($requestLogData->url, PHP_URL_PATH) ?: '/',
                'method' => $requestLogData->method,
                'api_version' => $requestLogData->request['api_version'] ?? 'default',
                'request_at' => $requestLogData->request['timestamp'],
                'response_at' => $requestLogData->response['timestamp'],
                'response_status' => $requestLogData->statusCode,
                'is_error' => ! $requestLogData->success,
            ];

            $requestLogDataMap[$requestLogData->id] = $requestLogData;
        }

        // Bulk store all request logs at once.
        try {
            $requestLogService->bulkStoreRequestLogs($logDataArray);

            // Fire events for each log item (events stay individual for extensibility).
            $requestLogClass = config('request-logs.models.request_log');
            foreach ($requestLogDataMap as $requestId => $requestLogData) {
                try {
                    $requestLog = $requestLogClass::where('request_id', $requestId)->first();

                    if ($requestLog) {
                        CompleteRequestLogEvent::dispatch(
                            $requestId,
                            $requestLog->id,
                            $tracker,
                            $requestLogData
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('Error firing CompleteRequestLogEvent: '.$e->getMessage(), [
                        'request_id' => $requestId,
                        'exception' => $e,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error bulk storing request logs: '.$e->getMessage(), [
                'exception' => $e,
                'count' => count($logDataArray),
            ]);
        }

        // Clear the pending logs.
        $this->clear();
    }
}
