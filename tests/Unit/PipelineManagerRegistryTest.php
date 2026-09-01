<?php

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\RequestLogPipelineManager;

/**
 * The index half of registry-kernel 38's migration. The tripwire comes first deliberately: a harness
 * that does not boot `PopcornServiceProvider` still auto-resolves a `RegistryIndex`, so every
 * assertion below would pass over a fresh throwaway object and prove nothing (sweep brief §3b
 * finding 2).
 */
it('shares one RegistryIndex — the harness tripwire every index assertion below depends on', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('routes request-logs.channels to the pipeline manager', function () {
    $index = app(RegistryIndex::class);

    expect($index->routeTo('request-logs.channels.request_logs_raw'))
        ->toBeInstanceOf(RequestLogPipelineManager::class);
});

it('exposes the configured channels through the index', function () {
    $index = app(RegistryIndex::class);

    // ⚠️ `RegistryIndex::has()`/`resolve()` address REGISTRIES, not entries — `$this->registries` is
    // the root => store map. `has('request-logs.channels.test_redacted')` is false while the entry is
    // right there, which is the same shape AGENTS.md records for `RegistryIndex::register()`. Route
    // first, then ask the store.
    $channels = $index->routeTo('request-logs.channels.test_redacted');

    // The harness configures `test_raw` and `test_redacted` in TestCase::getEnvironmentSetUp().
    expect($index->has('request-logs.channels'))->toBeTrue()
        ->and($channels)->not->toBeNull()
        ->and($channels->has('request-logs.channels.test_redacted'))->toBeTrue()
        ->and($channels->resolve('request-logs.channels.test_redacted'))->toContain(CommonBodyFieldsRedactor::class);
});

it('is a kernel Registry whose keys carry the declared root', function () {
    $manager = new RequestLogPipelineManager;
    $manager->addChannel('probe_channel', []);

    expect($manager)->toBeInstanceOf(Registry::class)
        ->and($manager->has('probe_channel'))->toBeTrue()
        ->and(array_map('strval', $manager->keys()))->toBe(['request-logs.channels.probe_channel']);
});
