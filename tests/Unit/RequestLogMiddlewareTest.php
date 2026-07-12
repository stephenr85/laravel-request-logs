<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Http\Middleware\RequestLogMiddleware;
use Rushing\RequestLogs\Services\RequestLogService;
use Rushing\RequestLogs\Services\RequestLogTracker;

beforeEach(function () {
    $this->requestLogService = Mockery::mock(RequestLogService::class);
    $this->middleware = new RequestLogMiddleware(
        $this->requestLogService
    );
    $this->requestId = (string) Str::uuid();
});

test('startRequestLogData creates RequestLogData with correct request data', function () {
    $request = Request::create('/api/users', 'POST', ['name' => 'John']);
    $request->headers->set('Accept-Version', '2.0');
    $request->headers->set('User-Agent', 'TestAgent');

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('startRequestLogData');
    $method->setAccessible(true);

    $requestLogData = $method->invoke($this->middleware, $request, $this->requestId);

    expect($requestLogData)->toBeInstanceOf(RequestLogData::class)
        ->and($requestLogData->id)->toBe($this->requestId)
        ->and($requestLogData->method)->toBe('POST')
        ->and($requestLogData->url)->toBe('http://localhost/api/users')
        ->and($requestLogData->operationName)->toBe('POST api/users')
        ->and($requestLogData->request['headers'])->toHaveKey('accept-version')
        ->and($requestLogData->request['body'])->toBe(['name' => 'John'])
        ->and($requestLogData->request['api_version'])->toBe('2.0')
        ->and($requestLogData->request)->toHaveKey('timestamp');
});

test('completeRequestLogData adds response data correctly', function () {
    $requestLogData = RequestLogData::instance(['id' => $this->requestId]);
    $response = new Response(
        json_encode(['success' => true]),
        200,
        ['Content-Type' => 'application/json']
    );

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('completeRequestLogData');
    $method->setAccessible(true);

    $method->invoke($this->middleware, $requestLogData, $response);

    expect($requestLogData->statusCode)->toBe(200)
        ->and($requestLogData->success)->toBeTrue()
        ->and($requestLogData->response['headers'])->toHaveKey('content-type')
        ->and($requestLogData->response['body'])->toBe(['success' => true])
        ->and($requestLogData->response)->toHaveKey('timestamp');
});

test('completeRequestLogData handles error responses correctly', function () {
    $requestLogData = RequestLogData::instance(['id' => $this->requestId]);
    $response = new Response(
        json_encode(['error' => 'Not found']),
        404,
        ['Content-Type' => 'application/json']
    );

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('completeRequestLogData');
    $method->setAccessible(true);

    $method->invoke($this->middleware, $requestLogData, $response);

    expect($requestLogData->statusCode)->toBe(404)
        ->and($requestLogData->success)->toBeFalse()
        ->and($requestLogData->response['body'])->toBe(['error' => 'Not found']);
});

test('getOperationName returns route name when available', function () {
    $request = Request::create('/api/users', 'GET');

    $route = Mockery::mock();
    $route->shouldReceive('getName')->andReturn('users.index');
    $request->setRouteResolver(function () use ($route) {
        return $route;
    });

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('getOperationName');
    $method->setAccessible(true);

    $operationName = $method->invoke($this->middleware, $request);

    expect($operationName)->toBe('users.index');
});

test('getOperationName falls back to method and path', function () {
    $request = Request::create('/api/users', 'GET');

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('getOperationName');
    $method->setAccessible(true);

    $operationName = $method->invoke($this->middleware, $request);

    expect($operationName)->toBe('GET api/users');
});

test('getResponseBody handles JSON content correctly', function () {
    $response = new Response(
        json_encode(['data' => 'test']),
        200,
        ['Content-Type' => 'application/json']
    );

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('getResponseBody');
    $method->setAccessible(true);

    $body = $method->invoke($this->middleware, $response);

    expect($body)->toBe(['data' => 'test']);
});

test('getResponseBody handles non-JSON content correctly', function () {
    $response = new Response('Plain text content', 200, ['Content-Type' => 'text/plain']);

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('getResponseBody');
    $method->setAccessible(true);

    $body = $method->invoke($this->middleware, $response);

    expect($body)->toBe('Plain text content');
});

test('shouldLogRequest returns false for OPTIONS requests', function () {
    $request = Request::create('/api/test', 'OPTIONS');

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('shouldLogRequest');
    $method->setAccessible(true);

    $shouldLog = $method->invoke($this->middleware, $request);

    expect($shouldLog)->toBeFalse();
});

test('shouldLogRequest returns false for excluded paths', function () {
    config(['request-logs.exclude_paths' => ['api/docs', 'api/health']]);

    $request = Request::create('/api/docs', 'GET');

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('shouldLogRequest');
    $method->setAccessible(true);

    $shouldLog = $method->invoke($this->middleware, $request);

    expect($shouldLog)->toBeFalse();
});

test('shouldLogRequest returns true for valid requests', function () {
    $request = Request::create('/api/users', 'GET');

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('shouldLogRequest');
    $method->setAccessible(true);

    $shouldLog = $method->invoke($this->middleware, $request);

    expect($shouldLog)->toBeTrue();
});

test('storeRequestLog creates database record correctly', function () {
    $requestLogData = RequestLogData::instance([
        'id' => $this->requestId,
        'operationName' => 'test.create',
        'url' => 'http://localhost/api/test',
        'success' => true,
        'statusCode' => 200,
        'method' => 'POST',
        'request' => ['api_version' => '2.0', 'timestamp' => now()->toIso8601String()],
        'response' => ['timestamp' => now()->toIso8601String()],
    ]);

    $expectedLogData = [
        'request_id' => $this->requestId,
        'path' => '/api/test',
        'method' => 'POST',
        'api_version' => '2.0',
        'request_at' => $requestLogData->request['timestamp'],
        'response_at' => $requestLogData->response['timestamp'],
        'response_status' => 200,
        'is_error' => false,
    ];

    $mockRequestLog = Mockery::mock();
    $this->requestLogService->shouldReceive('storeRequestLog')
        ->once()
        ->with($expectedLogData)
        ->andReturn($mockRequestLog);

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('storeRequestLog');
    $method->setAccessible(true);

    $result = $method->invoke($this->middleware, $requestLogData);

    expect($result)->toBe($mockRequestLog);
});

test('fireCompleteEvent fires event with tracked models and RequestLogData', function () {
    Event::fake();

    $requestLogData = RequestLogData::instance(['id' => $this->requestId]);

    $mockRequestLog = Mockery::mock();
    $mockRequestLog->id = 'request-log-id';

    $mockTracker = Mockery::mock(RequestLogTracker::class);
    $mockModels = collect([
        ['model_class' => 'App\Models\User', 'model_id' => 1],
        ['model_class' => 'App\Models\Order', 'model_id' => 2],
    ]);

    $mockTracker->shouldReceive('getModelsForRequest')
        ->once()
        ->with($this->requestId)
        ->andReturn($mockModels);

    $this->app->instance(RequestLogTracker::class, $mockTracker);

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('fireCompleteEvent');
    $method->setAccessible(true);

    $method->invoke($this->middleware, $requestLogData, $mockRequestLog);

    Event::assertDispatched(CompleteRequestLogEvent::class, function ($event) use ($requestLogData) {
        return $event->requestId === $this->requestId
            && $event->requestLogId === 'request-log-id'
            && $event->tracker->getModelsForRequest($this->requestId)->count() === 2
            && $event->requestLogData === $requestLogData;
    });
});

test('fireCompleteEvent does not fire when no request log', function () {
    Event::fake();

    $requestLogData = RequestLogData::instance(['id' => $this->requestId]);

    $reflection = new ReflectionClass($this->middleware);
    $method = $reflection->getMethod('fireCompleteEvent');
    $method->setAccessible(true);

    $method->invoke($this->middleware, $requestLogData, null);

    Event::assertNotDispatched(CompleteRequestLogEvent::class);
});
