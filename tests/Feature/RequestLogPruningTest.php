<?php

use Illuminate\Support\Str;
use Rushing\RequestLogs\Models\RequestLog;

function makeRequestLog(string $ageHours): RequestLog
{
    $log = RequestLog::create([
        'request_id' => (string) Str::uuid(),
        'path' => 'api/test',
        'method' => 'GET',
        'api_version' => 'default',
        'request_at' => now(),
        'response_at' => now(),
        'response_status' => 200,
        'is_error' => false,
    ]);

    // Backdate created_at so the prunable window can be exercised.
    $log->created_at = now()->subHours((int) $ageHours);
    $log->save();

    return $log;
}

test('prune removes rows older than the configured ttl and keeps recent ones', function () {
    config(['request-logs.database.pruning.ttl_hours' => 24]);

    $old = makeRequestLog(1000);
    $recent = makeRequestLog(1);

    expect(RequestLog::count())->toBe(2);

    (new RequestLog)->pruneAll();

    expect(RequestLog::count())->toBe(1)
        ->and(RequestLog::whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(RequestLog::whereKey($old->getKey())->exists())->toBeFalse();
});
