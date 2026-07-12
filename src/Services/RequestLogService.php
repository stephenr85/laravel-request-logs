<?php

namespace Rushing\RequestLogs\Services;

use Carbon\Carbon;

class RequestLogService
{
    /**
     * Store a request log record in the database.
     *
     * @return mixed The created or updated RequestLog model
     */
    public function storeRequestLog(array $logData)
    {
        // Get the model class from config.
        $requestLogClass = config('request-logs.models.request_log');

        $now = now();

        // Prepare the data for upsert.
        $data = [
            'request_id' => $logData['request_id'],
            'path' => $logData['path'],
            'method' => $logData['method'],
            'api_version' => $logData['api_version'],
            'request_at' => Carbon::parse($logData['request_at']),
            'response_at' => isset($logData['response_at']) ? Carbon::parse($logData['response_at']) : null,
            'response_status' => $logData['response_status'] ?? null,
            'is_error' => $logData['is_error'] ?? false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Use upsert to avoid the N+1 SELECT-then-write pattern.
        // This performs INSERT ... ON CONFLICT DO UPDATE at the database level.
        $requestLogClass::upsert(
            [$data],
            ['request_id'], // Unique identifier
            ['path', 'method', 'api_version', 'response_at', 'response_status', 'is_error', 'updated_at'] // Fields to update on conflict
        );

        // Fetch and return the record so callers get the model instance with its ID.
        return $requestLogClass::where('request_id', $logData['request_id'])->first();
    }

    /**
     * Bulk store multiple request logs in the database.
     *
     * @param  array  $logDataArray  Array of log data items
     */
    public function bulkStoreRequestLogs(array $logDataArray): void
    {
        if (empty($logDataArray)) {
            return;
        }

        // Get the model class from config.
        $requestLogClass = config('request-logs.models.request_log');

        // Prepare all data for a bulk upsert.
        $dataToUpsert = [];
        $now = now();

        foreach ($logDataArray as $logData) {
            $dataToUpsert[] = [
                'request_id' => $logData['request_id'],
                'path' => $logData['path'],
                'method' => $logData['method'],
                'api_version' => $logData['api_version'],
                'request_at' => Carbon::parse($logData['request_at']),
                'response_at' => isset($logData['response_at']) ? Carbon::parse($logData['response_at']) : null,
                'response_status' => $logData['response_status'] ?? null,
                'is_error' => $logData['is_error'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk upsert all records at once (timestamps must be manually set for upsert).
        $requestLogClass::upsert(
            $dataToUpsert,
            ['request_id'], // Unique identifier
            ['path', 'method', 'api_version', 'response_at', 'response_status', 'is_error', 'updated_at'] // Fields to update on conflict
        );
    }
}
