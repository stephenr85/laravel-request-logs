<?php

use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Models\RequestLog;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;

return [
    'enabled' => env('REQUEST_LOGS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Correlation Settings
    |--------------------------------------------------------------------------
    |
    | Configure request correlation/tracking for audit trails and model
    | association. This provides request correlation, not idempotency
    | (duplicate prevention). The default header is the conventional
    | X-Request-Id; set `ensure_header` to false to only log requests that
    | already carry a correlation ID.
    |
    */
    'correlation' => [
        'header_name' => env('REQUEST_LOGS_HEADER', 'X-Request-Id'),
        'ensure_header' => env('REQUEST_LOGS_ENSURE_HEADER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound Request Logging Settings
    |--------------------------------------------------------------------------
    |
    | Configure logging for outbound HTTP requests made via Guzzle/PSR clients.
    | This captures the calls your application makes to external services.
    |
    */
    'outbound' => [
        'enabled' => env('REQUEST_LOGS_OUTBOUND_ENABLED', true),
        'exclude_hosts' => [
            // Hosts to exclude from outbound logging (supports wildcards)
            // 'localhost',
            // '*.internal.company.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Exclusions
    |--------------------------------------------------------------------------
    |
    | Configure inbound paths that should be excluded from logging.
    |
    */
    'exclude_paths' => [
        // Paths to exclude from logging (supports wildcards)
        // 'health-check',
        // 'api/internal/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Configure the channels that request logs will be sent to. Each key
    | corresponds to a Laravel log channel name that must be configured in
    | config/logging.php. Each channel runs its own pipeline of redactors, so
    | raw and redacted destinations can have different sensitivity policies.
    |
    | Format: 'channel_name' => [redactor_configs...]
    |
    */
    'channels' => [
        'request_logs_raw' => [],

        'request_logs_redacted' => [
            CommonHeaderFieldsRedactor::class,
            CommonBodyFieldsRedactor::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Settings
    |--------------------------------------------------------------------------
    |
    | The connection is resolved from here so the host decides whether the
    | request_logs table lives on the central connection or per-tenant. A null
    | value uses the application's default connection.
    |
    */
    'database' => [
        'connection' => env('REQUEST_LOGS_CONNECTION'),
        'pruning' => [
            'ttl_hours' => env('REQUEST_LOGS_TTL_HOURS', 24 * 365), // Default 365 days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Class Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the data class used by the package. Override with your own
    | extension of \Rushing\RequestLogs\Data\RequestLogData.
    |
    */
    'data' => [
        'request_log_data' => RequestLogData::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the model used by the package. Override with your own
    | extension of \Rushing\RequestLogs\Models\RequestLog.
    |
    */
    'models' => [
        'request_log' => RequestLog::class,
    ],
];
