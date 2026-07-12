<?php

namespace Rushing\RequestLogs\Data;

use Spatie\LaravelData\Data;

class RequestLogData extends Data
{
    public function __construct(
        public string $id,
        public string $operationName = '',
        public string $url = '',
        public bool $success = false,
        public int $statusCode = 0,
        public string $method = 'GET',
        public array $request = ['body' => [], 'headers' => []],
        public array $response = ['body' => [], 'headers' => []],
        public array $meta = []
    ) {}

    /**
     * Map this data to the lightweight `request_logs` reference row. Single
     * source of truth for the mapping shared by the inbound middleware, the
     * Guzzle middleware, and the bulk collector.
     */
    public function toRowArray(): array
    {
        return [
            'request_id' => $this->id,
            'path' => parse_url($this->url, PHP_URL_PATH) ?: '/',
            'method' => $this->method,
            'api_version' => $this->request['api_version'] ?? 'default',
            'request_at' => $this->request['timestamp'] ?? null,
            'response_at' => $this->response['timestamp'] ?? null,
            'response_status' => $this->statusCode,
            'is_error' => ! $this->success,
        ];
    }

    /**
     * Get a new instance using the configured data class.
     */
    public static function instance(array $data = []): static
    {
        $dataClass = config('request-logs.data.request_log_data', static::class);

        if (! empty($data)) {
            return $dataClass::from($data);
        }

        return new $dataClass('', '', '', false, 0, 'GET', [], [], []);
    }
}
