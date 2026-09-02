<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Utils;
use Rushing\RequestLogs\Http\Middleware\GuzzleRequestLogMiddleware;
use Rushing\RequestLogs\Models\RequestLog;
use Rushing\RequestLogs\Services\RequestLogCollector;
use Rushing\RequestLogs\Support\BodyCapture;

beforeEach(function () {
    $this->collector = app(RequestLogCollector::class);
    $this->collector->clear();
    $this->mock = new MockHandler;
});

function guzzleClient(MockHandler $mock): Client
{
    $stack = HandlerStack::create($mock);
    $stack->push(app(GuzzleRequestLogMiddleware::class));

    return new Client(['handler' => $stack]);
}

test('successful outbound calls are captured and tagged as outbound', function () {
    $this->mock->append(new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])));

    guzzleClient($this->mock)->get('https://api.example.com/widgets');

    $pending = $this->collector->getPendingLogs();
    expect($pending)->toHaveCount(1);

    $log = $pending[0];
    expect($log->success)->toBeTrue()
        ->and($log->statusCode)->toBe(200)
        ->and($log->method)->toBe('GET')
        ->and($log->meta['type'])->toBe('outbound')
        ->and($log->meta['client'])->toBe('guzzle');
});

test('failed outbound calls are captured with the error status', function () {
    $this->mock->append(new GuzzleResponse(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'boom'])));

    try {
        guzzleClient($this->mock)->get('https://api.example.com/widgets');
    } catch (RequestException $e) {
        // Expected — Guzzle throws on 5xx by default.
    }

    $pending = $this->collector->getPendingLogs();
    expect($pending)->toHaveCount(1);
    expect($pending[0]->success)->toBeFalse()
        ->and($pending[0]->statusCode)->toBe(500);
});

test('excluded hosts are not logged', function () {
    config(['request-logs.outbound.exclude_hosts' => ['*.excluded.test']]);
    $this->mock->append(new GuzzleResponse(200));

    guzzleClient($this->mock)->get('https://api.excluded.test/thing');

    expect($this->collector->getPendingLogs())->toBeEmpty();
});

test('a per-request skip option bypasses logging', function () {
    $this->mock->append(new GuzzleResponse(200));

    guzzleClient($this->mock)->get('https://api.example.com/widgets', ['request_logs_skip' => true]);

    expect($this->collector->getPendingLogs())->toBeEmpty();
});

test('the correlation id is propagated to the outbound request headers', function () {
    $this->mock->append(new GuzzleResponse(200));

    guzzleClient($this->mock)->get('https://api.example.com/widgets');

    $sent = $this->mock->getLastRequest();
    expect($sent->getHeaderLine('X-Request-Id'))->not->toBe('');

    // The captured log uses that same correlation id.
    expect($this->collector->getPendingLogs()[0]->id)->toBe($sent->getHeaderLine('X-Request-Id'));
});

test('flushing the collector persists the outbound rows and fires the event', function () {
    $this->mock->append(new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])));

    guzzleClient($this->mock)->get('https://api.example.com/widgets');

    $this->collector->flushLogs();

    expect(RequestLog::where('is_error', false)->count())->toBe(1);
    expect($this->collector->getPendingLogs())->toBeEmpty();
});

test('outbound logging can be disabled entirely', function () {
    config(['request-logs.outbound.enabled' => false]);
    $this->mock->append(new GuzzleResponse(200));

    guzzleClient($this->mock)->get('https://api.example.com/widgets');

    expect($this->collector->getPendingLogs())->toBeEmpty();
});

test('a non-seekable streamed response body reaches the caller intact', function () {
    // Guzzle `stream => true` hands back a socket-backed, NON-seekable body that can be read exactly once.
    // Measured at the flagship (file-handling 11): logging it here consumed Prism's whole OpenAI SSE stream
    // before the parser read a byte, so every chat reply committed as empty. The log must describe such a
    // body, never read it.
    $sse = "data: {\"type\":\"response.output_text.delta\",\"delta\":\"OK\"}\n\n";
    $this->mock->append(new GuzzleResponse(
        200,
        ['Content-Type' => 'text/event-stream'],
        new NoSeekStream(Utils::streamFor($sse)),
    ));

    $response = guzzleClient($this->mock)->get('https://api.example.com/stream', ['stream' => true]);

    expect($response->getBody()->getContents())->toBe($sse);

    $pending = $this->collector->getPendingLogs();
    expect($pending)->toHaveCount(1)
        ->and($pending[0]->response['body'])->toBe(BodyCapture::describeStream('text/event-stream'));
});

test('a non-seekable request body is not consumed before the transport sends it', function () {
    $this->mock->append(new GuzzleResponse(200));
    $payload = '{"streamed":"upload"}';

    guzzleClient($this->mock)->post('https://api.example.com/upload', [
        'body' => new NoSeekStream(Utils::streamFor($payload)),
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    expect($this->mock->getLastRequest()->getBody()->getContents())->toBe($payload);

    $pending = $this->collector->getPendingLogs();
    expect($pending)->toHaveCount(1)
        ->and($pending[0]->request['body'])->toBe(BodyCapture::describeStream('application/json'));
});
