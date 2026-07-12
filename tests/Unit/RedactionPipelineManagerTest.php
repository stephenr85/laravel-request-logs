<?php

use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Redactors\DotNotationRedactor;
use Rushing\RequestLogs\RequestLogPipelineManager;

beforeEach(function () {
    $container = new Container;
    $container->bind(Pipeline::class, function () use ($container) {
        return new Pipeline($container);
    });
    Container::setInstance($container);

    // Clear the singleton instance to ensure a fresh instance for each test.
    app()->forgetInstance(RequestLogPipelineManager::class);
});

afterEach(function () {
    Mockery::close();
});

it('loads channels from config', function () {
    $manager = app(RequestLogPipelineManager::class);

    $config = [
        'test_raw' => [],
        'test_redacted' => [[DotNotationRedactor::class, ['test.path']]],
    ];

    $manager->loadChannels($config);

    expect($manager->getChannels())->toBe($config);
});

it('adds single channel', function () {
    $manager = app(RequestLogPipelineManager::class);

    $manager->addChannel('test_channel', [[DotNotationRedactor::class, ['test.path']]]);

    $channels = $manager->getChannels();
    expect($channels)->toHaveKey('test_channel');
    expect($channels['test_channel'])->toBe([[DotNotationRedactor::class, ['test.path']]]);
});

it('registers processors when configured', function () {
    $manager = app(RequestLogPipelineManager::class);

    $mockLogger = Mockery::mock('Psr\\Log\\LoggerInterface');
    $mockLogger->shouldReceive('pushProcessor')->once();

    $mockChannel = Mockery::mock();
    $mockChannel->shouldReceive('getLogger')->andReturn($mockLogger);

    Log::shouldReceive('channel')
        ->with('test_channel')
        ->andReturn($mockChannel);

    $manager->addChannel('test_channel', [[DotNotationRedactor::class, ['test.path']]]);
    $manager->registerProcessors();

    expect(true)->toBeTrue();
});

it('clears channels', function () {
    $manager = app(RequestLogPipelineManager::class);
    $manager->addChannel('test', []);

    expect($manager->getChannels())->not->toBeEmpty();

    $manager->clearChannels();

    expect($manager->getChannels())->toBeEmpty();
});
