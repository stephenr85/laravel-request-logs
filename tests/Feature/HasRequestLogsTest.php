<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Listeners\CompleteRequestLogListener;
use Rushing\RequestLogs\Models\RequestLog;
use Rushing\RequestLogs\Services\RequestLogTracker;
use Rushing\RequestLogs\Tests\Models\TestModel;

beforeEach(function () {
    Schema::create('test_models', function ($table) {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $this->correlationId = (string) Str::uuid();

    // Simulate an inbound request that carries the correlation header, so the
    // trait's saved-hook tracks any models saved during it.
    $request = Request::create('/api/test', 'POST');
    $request->headers->set('X-Request-Id', $this->correlationId);
    $this->app->instance('request', $request);
});

test('a model saved during a correlated request is associated after the listener runs', function () {
    // Saving the model registers it with the tracker (via the trait's saved hook).
    $model = TestModel::create(['name' => 'foo']);

    // The middleware would have stored the request-log row; do it directly here.
    $requestLog = RequestLog::create([
        'request_id' => $this->correlationId,
        'path' => 'api/test',
        'method' => 'POST',
        'api_version' => 'default',
        'request_at' => now(),
        'response_at' => now()->addSeconds(1),
        'response_status' => 200,
        'is_error' => false,
    ]);

    $tracker = app(RequestLogTracker::class);
    expect($tracker->getModelsForRequest($this->correlationId))->toHaveCount(1);

    // Run the queued listener directly.
    $event = new CompleteRequestLogEvent($this->correlationId, $requestLog->id, $tracker);
    (new CompleteRequestLogListener)->handle($event);

    // The association row exists.
    $this->assertDatabaseHas('request_log_models', [
        'request_log_id' => $requestLog->id,
        'model_type' => TestModel::class,
        'model_id' => $model->getKey(),
    ]);

    // Queryable request -> models (forward).
    expect($requestLog->getRelatedModels(TestModel::class)->count())->toBe(1);

    // Queryable model -> requests (reverse).
    expect($model->requestLogs()->count())->toBe(1)
        ->and($model->latestRequestLog()->id)->toBe($requestLog->id);
});

test('nothing is tracked when the request has no correlation header', function () {
    // Replace the bound request with one lacking the correlation header.
    $this->app->instance('request', Request::create('/api/test', 'POST'));

    TestModel::create(['name' => 'bar']);

    $tracker = app(RequestLogTracker::class);
    expect($tracker->getRequestIds())->toBeEmpty();
});

test('the listener resolves a tracked morph alias and preserves the stored morph type', function () {
    $map = Illuminate\Database\Eloquent\Relations\Relation::morphMap();
    Illuminate\Database\Eloquent\Relations\Relation::morphMap(['hook' => TestModel::class]);

    try {
        $model = TestModel::create(['name' => 'aliased']);
        $tracker = app(RequestLogTracker::class);
        $tracker->clearAll();
        $tracker->addModel($this->correlationId, 'hook', $model->getKey());
        $requestLog = RequestLog::create([
            'request_id' => $this->correlationId,
            'path' => 'api/test', 'method' => 'POST', 'api_version' => 'default',
            'request_at' => now(), 'response_at' => now(),
            'response_status' => 200, 'is_error' => false,
        ]);

        (new CompleteRequestLogListener)->handle(new CompleteRequestLogEvent(
            $this->correlationId, $requestLog->id, $tracker,
        ));

        $this->assertDatabaseHas('request_log_models', [
            'request_log_id' => $requestLog->id,
            'model_type' => 'hook', 'model_id' => $model->getKey(),
        ]);
        expect($tracker->getRequestIds())->toBeEmpty();
    } finally {
        Illuminate\Database\Eloquent\Relations\Relation::morphMap($map, false);
    }
});

test('an invalid tracked class cannot escape the logging listener as an Error', function () {
    $model = TestModel::create(['name' => 'valid']);
    $tracker = app(RequestLogTracker::class);
    $tracker->addModel($this->correlationId, 'MissingRequestLogTrackedClass', 'missing');
    $requestLog = RequestLog::create([
        'request_id' => $this->correlationId,
        'path' => 'api/test', 'method' => 'POST', 'api_version' => 'default',
        'request_at' => now(), 'response_at' => now(),
        'response_status' => 200, 'is_error' => false,
    ]);

    Illuminate\Support\Facades\Log::shouldReceive('error')->once()
        ->withArgs(fn ($message, $context) => str_contains($message, 'Error preparing models')
            && $context['exception'] instanceof Error);
    (new CompleteRequestLogListener)->handle(new CompleteRequestLogEvent(
        $this->correlationId, $requestLog->id, $tracker,
    ));

    $this->assertDatabaseHas('request_log_models', [
        'request_log_id' => $requestLog->id,
        'model_type' => TestModel::class, 'model_id' => $model->getKey(),
    ]);
    expect($tracker->getRequestIds())->toBeEmpty();
});
