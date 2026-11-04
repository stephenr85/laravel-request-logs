<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Rushing\RequestLogs\Redactors\DotNotationRedactor;
use Rushing\RequestLogs\RequestLogPipelineManager;

/**
 * A channel registered after the manager is built is redacted from its first record, and a channel whose
 * pipeline is replaced runs the new pipeline, not the one it had when processors were first attached.
 */
beforeEach(function () {
    config()->set('logging.channels.late_audit', ['driver' => 'monolog', 'handler' => TestHandler::class]);
});

function lateAuditRecords(): array
{
    /** @var TestHandler $handler */
    $handler = Log::channel('late_audit')->getLogger()->getHandlers()[0];

    return $handler->getRecords();
}

it('redacts a channel added with addChannel() after the manager is built', function () {
    $manager = app(RequestLogPipelineManager::class);

    $manager->addChannel('late_audit', [[DotNotationRedactor::class, ['request.body.password']]]);

    Log::channel('late_audit')->info('POST /login', ['request' => ['body' => ['password' => 'hunter2']]]);

    expect(lateAuditRecords()[0]->context['request']['body']['password'])->not->toBe('hunter2');
});

it('runs the replacement pipeline once a channel is re-registered', function () {
    $manager = app(RequestLogPipelineManager::class);

    $manager->addChannel('late_audit', []);
    $manager->registerProcessors();
    $manager->register('late_audit', [[DotNotationRedactor::class, ['request.body.password']]]);

    Log::channel('late_audit')->info('POST /login', ['request' => ['body' => ['password' => 'hunter2']]]);

    expect(lateAuditRecords()[0]->context['request']['body']['password'])->not->toBe('hunter2');
});

it('attaches one processor per channel however often registerProcessors() runs', function () {
    $manager = app(RequestLogPipelineManager::class);
    $before = count(Log::channel('late_audit')->getLogger()->getProcessors()); // Laravel's own context processor

    $manager->addChannel('late_audit', [[DotNotationRedactor::class, ['request.body.password']]]);
    $manager->registerProcessors();
    $manager->registerProcessors();

    expect(Log::channel('late_audit')->getLogger()->getProcessors())->toHaveCount($before + 1);
});
