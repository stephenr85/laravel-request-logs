<?php

use Illuminate\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Monolog\Level;
use Monolog\LogRecord;
use Rushing\RequestLogs\Processors\RequestLogProcessor;
use Rushing\RequestLogs\Redactors\DotNotationRedactor;

beforeEach(function () {
    $container = new Container;
    $container->bind(Pipeline::class, function () use ($container) {
        return new Pipeline($container);
    });
    Container::setInstance($container);
});

it('processes log record with redactors', function () {
    $processor = new RequestLogProcessor([
        [DotNotationRedactor::class, ['request.body.password'], '[REDACTED]'],
    ]);

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
        context: [
            'request' => [
                'body' => [
                    'username' => 'john',
                    'password' => 'secret123',
                ],
            ],
        ],
    );

    $processedRecord = $processor($record);

    expect($processedRecord->context['request']['body']['password'])->toBe('[REDACTED]');
    expect($processedRecord->context['request']['body']['username'])->toBe('john');
});

it('returns record unchanged when no redactors', function () {
    $processor = new RequestLogProcessor([]);

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
        context: [
            'request' => [
                'body' => ['password' => 'secret123'],
            ],
        ],
    );

    $processedRecord = $processor($record);

    expect($processedRecord->context['request']['body']['password'])->toBe('secret123');
});

it('returns record unchanged when no context', function () {
    $processor = new RequestLogProcessor([
        [DotNotationRedactor::class, ['request.body.password'], '[REDACTED]'],
    ]);

    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'Test message',
        context: [],
    );

    $processedRecord = $processor($record);

    expect($processedRecord->context)->toBe([]);
});
