<?php

use Illuminate\Support\Str;
use Rushing\RequestLogs\Models\RequestLog;
use Rushing\RequestLogs\Services\RequestLogService;

beforeEach(function () {
    $this->service = new RequestLogService;
    $this->requestId = (string) Str::uuid();
});

test('it can create a request log record', function () {
    $logData = [
        'request_id' => $this->requestId,
        'path' => 'api/test',
        'method' => 'POST',
        'api_version' => '1.0',
        'request_at' => now()->toIso8601String(),
        'response_at' => now()->addSeconds(1)->toIso8601String(),
        'response_status' => 200,
        'is_error' => false,
    ];

    $requestLog = $this->service->storeRequestLog($logData);

    expect($requestLog)->toBeInstanceOf(RequestLog::class)
        ->and($requestLog->request_id)->toBe($this->requestId)
        ->and($requestLog->path)->toBe('api/test')
        ->and($requestLog->method)->toBe('POST')
        ->and($requestLog->api_version)->toBe('1.0')
        ->and($requestLog->response_status)->toBe(200)
        ->and($requestLog->is_error)->toBeFalse();

    $retrieved = RequestLog::where('request_id', $this->requestId)->first();
    expect($retrieved)->not->toBeNull()
        ->and($retrieved->id)->toBe($requestLog->id);
});

test('it upserts on a repeated request id instead of duplicating', function () {
    $base = [
        'request_id' => $this->requestId,
        'path' => 'api/test',
        'method' => 'POST',
        'api_version' => '1.0',
        'request_at' => now()->toIso8601String(),
        'response_at' => now()->addSeconds(1)->toIso8601String(),
        'response_status' => 200,
        'is_error' => false,
    ];

    $first = $this->service->storeRequestLog($base);

    $updated = $this->service->storeRequestLog(array_merge($base, [
        'response_status' => 500,
        'is_error' => true,
    ]));

    expect(RequestLog::where('request_id', $this->requestId)->count())->toBe(1)
        ->and($updated->id)->toBe($first->id)
        ->and($updated->response_status)->toBe(500)
        ->and($updated->is_error)->toBeTrue();
});
