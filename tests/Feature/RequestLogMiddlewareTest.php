<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Http\Middleware\RequestLogMiddleware;
use Rushing\RequestLogs\Models\RequestLog;

beforeEach(function () {
    Event::fake();
    $this->middleware = app(RequestLogMiddleware::class);
    $this->requestId = (string) Str::uuid();
});

test('middleware handles request and logs data correctly', function () {
    $request = Request::create('/api/test', 'POST', ['test_data' => 'value']);
    $request->headers->set('X-Request-Id', $this->requestId);
    $request->headers->set('Accept-Version', '2.0');

    $response = new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);

    $next = function ($req) use ($response) {
        return $response;
    };

    $result = $this->middleware->handle($request, $next);

    expect($result)->toBe($response);

    // The correlation header was preserved.
    expect($request->header('X-Request-Id'))->toBe($this->requestId);

    // The RequestLogData was stored on the request attributes.
    expect($request->attributes->has('requestLogData'))->toBeTrue();

    $requestLogData = $request->attributes->get('requestLogData');
    expect($requestLogData)->toBeInstanceOf(RequestLogData::class)
        ->and($requestLogData->id)->toBe($this->requestId)
        ->and($requestLogData->method)->toBe('POST')
        ->and($requestLogData->url)->toBe('http://localhost/api/test')
        ->and($requestLogData->request['api_version'])->toBe('2.0');

    $this->middleware->terminate($request, $response);

    // The RequestLogData was completed with response data.
    expect($requestLogData->statusCode)->toBe(200)
        ->and($requestLogData->success)->toBeTrue()
        ->and($requestLogData->response)->toHaveKey('headers')
        ->and($requestLogData->response)->toHaveKey('body')
        ->and($requestLogData->response)->toHaveKey('timestamp');

    // The completion event was fired with the RequestLogData.
    Event::assertDispatched(CompleteRequestLogEvent::class, function ($event) {
        return $event->requestId === $this->requestId
            && $event->requestLogData instanceof RequestLogData
            && $event->requestLogData->id === $this->requestId;
    });

    // The request log was stored in the database.
    $requestLog = RequestLog::where('request_id', $this->requestId)->first();
    expect($requestLog)->not->toBeNull()
        ->and($requestLog->path)->toBe('/api/test')
        ->and($requestLog->method)->toBe('POST')
        ->and($requestLog->api_version)->toBe('2.0')
        ->and($requestLog->response_status)->toBe(200)
        ->and($requestLog->is_error)->toBeFalse();
});

test('middleware auto-generates a correlation id when the header is missing', function () {
    $request = Request::create('/api/test', 'POST', ['test_data' => 'value']);
    $response = new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);

    $next = fn ($req) => $response;

    $this->middleware->handle($request, $next);

    $generated = $request->header('X-Request-Id');
    expect($generated)->not->toBeNull();

    $this->middleware->terminate($request, $response);

    expect(RequestLog::where('request_id', $generated)->exists())->toBeTrue();
});

test('middleware skips logging when the header is missing and ensure_header is false', function () {
    config(['request-logs.correlation.ensure_header' => false]);

    $request = Request::create('/api/test', 'POST');
    $response = new Response(json_encode(['success' => true]), 200, ['Content-Type' => 'application/json']);

    $next = fn ($req) => $response;

    $this->middleware->handle($request, $next);

    expect($request->attributes->has('requestLogData'))->toBeFalse();

    $this->middleware->terminate($request, $response);

    Event::assertNotDispatched(CompleteRequestLogEvent::class);
    expect(RequestLog::count())->toBe(0);
});

test('middleware skips logging for excluded paths', function () {
    config(['request-logs.exclude_paths' => ['api/docs', 'api/health']]);

    $request = Request::create('/api/docs', 'GET');
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Response('Docs content', 200);

    $next = function ($req) use ($response) {
        return $response;
    };

    $result = $this->middleware->handle($request, $next);

    expect($result)->toBe($response);

    $this->middleware->terminate($request, $response);

    Event::assertNotDispatched(CompleteRequestLogEvent::class);

    $requestLog = RequestLog::where('request_id', $this->requestId)->first();
    expect($requestLog)->toBeNull();
});

test('middleware handles error responses correctly', function () {
    $request = Request::create('/api/test', 'POST', ['test_data' => 'invalid']);
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Response(
        json_encode(['message' => 'Validation failed']),
        422,
        ['Content-Type' => 'application/json']
    );

    $next = function ($req) use ($response) {
        return $response;
    };

    $this->middleware->handle($request, $next);
    $this->middleware->terminate($request, $response);

    Event::assertDispatched(CompleteRequestLogEvent::class, function ($event) {
        return $event->requestId === $this->requestId
            && $event->requestLogData->statusCode === 422
            && $event->requestLogData->success === false;
    });

    $requestLog = RequestLog::where('request_id', $this->requestId)->first();
    expect($requestLog)->not->toBeNull()
        ->and($requestLog->response_status)->toBe(422)
        ->and($requestLog->is_error)->toBeTrue();
});
