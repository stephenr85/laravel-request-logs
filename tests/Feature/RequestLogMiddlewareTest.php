<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
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

test('middleware keeps an uploaded-file request body serializable', function () {
    // A multipart upload: Request::all() includes the UploadedFile object, which
    // is an SplFileInfo and cannot be serialized. The CompleteRequestLogListener is
    // ShouldQueue, so its payload (carrying RequestLogData) gets serialized — a raw
    // file in the body crashes any logged upload (test AND production, on a real
    // queue). The body must carry a serializable descriptor, never the file object.
    $file = UploadedFile::fake()->create('guide.mid', 1, 'audio/midi');

    $request = Request::create('/api/upload', 'POST', ['title' => 'song'], [], ['file' => $file]);
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Response(json_encode(['ok' => true]), 200, ['Content-Type' => 'application/json']);

    $this->middleware->handle($request, fn ($req) => $response);

    $requestLogData = $request->attributes->get('requestLogData');

    // The invariant the queue serializer needs: no SplFileInfo (UploadedFile /
    // Testing\File) survives anywhere in the logged payload.
    $hasFile = false;
    array_walk_recursive($requestLogData->request, function ($value) use (&$hasFile) {
        if ($value instanceof SplFileInfo) {
            $hasFile = true;
        }
    });
    expect($hasFile)->toBeFalse('the logged request payload still carries a raw file object');

    // The file survives as a descriptor, not the raw object, alongside scalar input.
    $body = $requestLogData->request['body'];
    expect($body)->toHaveKey('file')
        ->and($body['file'])->toBeArray()
        ->and($body['title'])->toBe('song');
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

test('middleware keeps a binary response body serializable', function () {
    // The response-axis twin of the uploaded-file case above. `terminate()` completes
    // RequestLogData with the response body and dispatches the queued completion event,
    // so the payload is json_encode'd — and json_encode throws InvalidPayloadException
    // ("Malformed UTF-8 characters") on raw PDF bytes. The request died AFTER the
    // document had already been rendered and returned.
    //
    // The wrong signal was "is the Content-Type application/json?", with every other
    // content type assumed to be loggable text. The signal that decides is whether the
    // body is TEXT at all.
    $pdf = "%PDF-1.7\n".random_bytes(512)."\n%%EOF";

    $request = Request::create('/api/v1/disclosures/1/export', 'GET', ['format' => 'pdf']);
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Response($pdf, 200, ['Content-Type' => 'application/pdf']);

    $this->middleware->handle($request, fn ($req) => $response);
    $this->middleware->terminate($request, $response);

    $requestLogData = $request->attributes->get('requestLogData');

    // The invariant the queue serializer needs — this is the call that used to throw.
    expect(json_encode($requestLogData->toArray()))->not->toBeFalse();

    $body = $requestLogData->response['body'];
    expect($body)->toBeArray()
        ->and($body['__binary__'])->toBeTrue()
        ->and($body['mime'])->toBe('application/pdf')
        ->and($body['size'])->toBe(strlen($pdf));
});

test('middleware still captures a text response body', function () {
    // The fix is general over content type, so prove it did not widen into "log nothing":
    // text/* and +json suffixes stay captured, JSON stays decoded.
    $request = Request::create('/api/v1/renderings/1', 'GET');
    $request->headers->set('X-Request-Id', $this->requestId);

    $csv = new Response("a,b\n1,2\n", 200, ['Content-Type' => 'text/csv; charset=utf-8']);
    $this->middleware->handle($request, fn ($req) => $csv);
    $this->middleware->terminate($request, $csv);
    expect($request->attributes->get('requestLogData')->response['body'])->toBe("a,b\n1,2\n");

    $request2 = Request::create('/api/v1/problem', 'GET');
    $request2->headers->set('X-Request-Id', (string) Str::uuid());
    $problem = new Response(json_encode(['title' => 'nope']), 422, ['Content-Type' => 'application/problem+json']);
    $this->middleware->handle($request2, fn ($req) => $problem);
    $this->middleware->terminate($request2, $problem);
    expect($request2->attributes->get('requestLogData')->response['body'])->toBe(['title' => 'nope']);
});

test('middleware describes a file-backed download without reading it', function () {
    // BinaryFileResponse::getContent() returns false — there is no in-memory body to
    // capture, only to describe. Same for a StreamedResponse.
    $path = tempnam(sys_get_temp_dir(), 'reqlog').'.bin';
    file_put_contents($path, random_bytes(1024));

    $request = Request::create('/api/v1/files/1/download', 'GET');
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Symfony\Component\HttpFoundation\BinaryFileResponse($path);
    $response->headers->set('Content-Type', 'application/octet-stream');

    $this->middleware->handle($request, fn ($req) => $response);
    $this->middleware->terminate($request, $response);

    $requestLogData = $request->attributes->get('requestLogData');
    expect(json_encode($requestLogData->toArray()))->not->toBeFalse()
        ->and($requestLogData->response['body']['__binary__'])->toBeTrue()
        ->and($requestLogData->response['body']['size'])->toBe(1024);

    @unlink($path);
});

test('middleware scrubs a non-utf8 request field', function () {
    // Request axis, same crash: a latin-1 form field is not valid UTF-8 either.
    $request = Request::create('/api/test', 'POST', ['name' => "Beyonc\xE9"]);
    $request->headers->set('X-Request-Id', $this->requestId);

    $response = new Response(json_encode(['ok' => true]), 200, ['Content-Type' => 'application/json']);
    $this->middleware->handle($request, fn ($req) => $response);
    $this->middleware->terminate($request, $response);

    $requestLogData = $request->attributes->get('requestLogData');
    expect(json_encode($requestLogData->toArray()))->not->toBeFalse()
        ->and($requestLogData->request['body']['name'])->toBeArray();
});
