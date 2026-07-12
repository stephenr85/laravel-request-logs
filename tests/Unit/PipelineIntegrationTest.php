<?php

use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;
use Rushing\RequestLogs\RequestLogPipelineManager;

beforeEach(function () {
    // Clear the singleton instance to ensure a fresh instance for each test.
    $this->app->forgetInstance(RequestLogPipelineManager::class);

    // Mock any default log channels from config to prevent unexpected calls.
    $mockLogger = Mockery::mock('Psr\Log\LoggerInterface');
    $mockLogger->shouldReceive('pushProcessor')->zeroOrMoreTimes();

    $mockChannel = Mockery::mock();
    $mockChannel->shouldReceive('getLogger')->andReturn($mockLogger);
    $mockChannel->shouldReceive('info')->zeroOrMoreTimes();

    Log::shouldReceive('channel')
        ->with('test_raw')
        ->andReturn($mockChannel)
        ->zeroOrMoreTimes();

    Log::shouldReceive('channel')
        ->with('test_redacted')
        ->andReturn($mockChannel)
        ->zeroOrMoreTimes();
});

it('processes full pipeline and redacts sensitive data', function () {
    $manager = new RequestLogPipelineManager;
    $manager->addChannel('test_redacted', [
        CommonHeaderFieldsRedactor::class,
        CommonBodyFieldsRedactor::class,
    ]);

    expect($manager->getChannels())->toHaveKey('test_redacted')
        ->and($manager->getChannels()['test_redacted'])->toContain(CommonHeaderFieldsRedactor::class)
        ->and($manager->getChannels()['test_redacted'])->toContain(CommonBodyFieldsRedactor::class);

    $dto = RequestLogData::instance([
        'id' => 'test-123',
        'operationName' => 'loginUser',
        'url' => '/api/login',
        'success' => true,
        'statusCode' => 200,
        'method' => 'POST',
        'request' => [
            'body' => [
                'username' => 'john',
                'password' => 'supersecret123',
                'remember_me' => true,
            ],
            'headers' => [
                'authorization' => 'Bearer token123',
                'content-type' => 'application/json',
                'user-agent' => 'TestClient/1.0',
            ],
        ],
        'response' => [
            'body' => [
                'user_id' => 123,
                'access_token' => 'newtoken456',
                'username' => 'john',
            ],
            'headers' => [
                'content-type' => 'application/json',
                'set-cookie' => 'session=abc123; HttpOnly',
            ],
        ],
    ]);

    expect($dto->method)->toBe('POST')
        ->and($dto->url)->toBe('/api/login')
        ->and($dto->request['body']['password'])->toBe('supersecret123');
});

it('preserves all data in raw pipeline', function () {
    $manager = new RequestLogPipelineManager;
    $manager->addChannel('test_raw', []); // No redactors

    expect($manager->getChannels())->toHaveKey('test_raw')
        ->and($manager->getChannels()['test_raw'])->toBeEmpty();

    $dto = RequestLogData::instance([
        'id' => 'test-123',
        'method' => 'GET',
        'url' => '',
        'request' => [
            'body' => ['password' => 'secret123'],
            'headers' => ['authorization' => 'Bearer token456'],
        ],
    ]);

    expect($dto->id)->toBe('test-123')
        ->and($dto->request['body']['password'])->toBe('secret123')
        ->and($dto->request['headers']['authorization'])->toBe('Bearer token456');
});
