<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use Rushing\RequestLogs\Models\RequestLog;

beforeEach(function () {
    $this->requestId = (string) Str::uuid();
});

test('it can calculate duration in milliseconds', function () {
    $requestTime = Carbon::now();
    $responseTime = $requestTime->copy()->addMilliseconds(500);

    $requestLog = new RequestLog([
        'request_id' => (string) Str::uuid(),
        'request_at' => $requestTime,
        'response_at' => $responseTime,
    ]);

    expect($requestLog->duration_ms)->toEqual($responseTime->diff($requestTime)->milliseconds)
        ->and($requestLog->getDurationFormatted())->toContain('ms');
});

test('it formats duration in seconds for longer requests', function () {
    $requestTime = Carbon::now();
    $responseTime = $requestTime->copy()->addSeconds(2.5);

    $requestLog = new RequestLog([
        'request_id' => (string) Str::uuid(),
        'request_at' => $requestTime,
        'response_at' => $responseTime,
    ]);

    expect($requestLog->duration_ms)->toEqual($responseTime->diff($requestTime)->milliseconds)
        ->and($requestLog->getDurationFormatted())->toContain('s');
});

test('it handles null duration when request or response times are missing', function () {
    $requestLog = new RequestLog([
        'request_id' => (string) Str::uuid(),
        'request_at' => now(),
        'response_at' => null,
    ]);

    expect($requestLog->duration_ms)->toBeNull()
        ->and($requestLog->getDurationFormatted())->toBeNull();
});

test('it can associate related models through morphToMany relationship', function () {
    $requestLog = RequestLog::create([
        'request_id' => $this->requestId,
        'path' => 'api/test',
        'method' => 'POST',
        'api_version' => '1.0',
        'request_at' => now(),
        'response_at' => now()->addSeconds(1),
        'response_status' => 200,
        'is_error' => false,
    ]);

    $modelClass = 'Rushing\\RequestLogs\\Tests\\Models\\TestModel';

    $relation = $requestLog->getRelatedModels($modelClass);

    expect($relation)->toBeInstanceOf(MorphToMany::class)
        ->and($relation->getMorphType())->toBe('model_type')
        ->and($relation->getRelated()->getMorphClass())->toBe($modelClass);
});
