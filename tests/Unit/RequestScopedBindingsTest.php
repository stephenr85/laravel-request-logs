<?php

use Rushing\RequestLogs\Services\RequestLogCollector;
use Rushing\RequestLogs\Services\RequestLogTracker;

/**
 * Both collection services must be rebuilt between requests/jobs.
 *
 * Under request-per-process PHP this file would be pointless. Under a persistent worker — Octane, or
 * the queue worker, which is one already — `singleton()` carries state across units of work and
 * `scoped()` does not.
 *
 * The collector's case is a correctness bug rather than a memory one: `$terminatingCallbackRegistered`
 * is sticky, so a carried-over instance registers the flush callback exactly once and every subsequent
 * request's `$pendingLogs` is never written. A logging package that silently stops logging is the worst
 * available failure, because the absence of rows reads as an absence of traffic.
 */
it('rebuilds the collector between units of work', function () {
    $first = app(RequestLogCollector::class);
    app()->forgetScopedInstances();

    // Identity, not existence. Asserting the object merely exists passes either way.
    expect(app(RequestLogCollector::class))->not->toBe($first);
});

it('rebuilds the tracker between units of work', function () {
    $first = app(RequestLogTracker::class);
    app()->forgetScopedInstances();

    expect(app(RequestLogTracker::class))->not->toBe($first);
});

it('still shares one instance within a single unit of work', function () {
    // The reason these were singletons — middleware, the Guzzle handler and the terminating callback
    // must see the same collector — is a property `scoped()` keeps. If this fails, the conversion
    // broke the thing the binding existed for.
    expect(app(RequestLogCollector::class))->toBe(app(RequestLogCollector::class))
        ->and(app(RequestLogTracker::class))->toBe(app(RequestLogTracker::class));
});

it('does not carry a request\'s pending logs into the next one', function () {
    // The behavioural half. A carried-over collector would hand the next request the previous one's
    // queue — and, because the terminating flag is sticky, never flush it.
    $collector = app(RequestLogCollector::class);
    expect($collector->getPendingLogs())->toBeEmpty();

    app()->forgetScopedInstances();

    expect(app(RequestLogCollector::class)->getPendingLogs())->toBeEmpty();
});
