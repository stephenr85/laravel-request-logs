<?php

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;

/**
 * The executability gate for this package's declared Data classes
 * (api-surface-coherence tickets 84/85).
 *
 * Testbench does not auto-discover, so `tests/TestCase.php` used to boot
 * `RequestLogsServiceProvider` and nothing else while hand-rolling seven `data.*` keys —
 * `config('data')` was a partial array, every unset key missing. Measured in
 * `splicewire/tower` the same omission was a FATAL rather than a failure.
 *
 * Declaring a Data class is only worth what the declaration can be EXECUTED to prove, so this
 * walks every concrete `BaseData` subclass under `src/` and runs the two halves of the
 * hydration path that `from()` / `validateAndCreate()` reach before touching a payload:
 *
 *  1. `DataConfig::getDataClass()` — the full reflection analysis: property types, attributes
 *     instantiated, casts / mappers / nested Data classes resolved.
 *  2. `::getValidationRules([])` — the rule inferrers plus any hand-written `rules()`,
 *     projected through the input name mapper.
 *
 * Neither needs a fixture payload, which is what lets it be data-driven.
 */
function requestLogsDataClasses(): array
{
    $root = dirname(__DIR__, 3).'/src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $classes = [];

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (substr($path, -4) !== '.php') {
            continue;
        }

        // Namespace is the path under src/, PSR-4'd onto Rushing\RequestLogs\.
        $relative = substr($path, strlen($root) + 1, -4);
        $class = 'Rushing\\RequestLogs\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, BaseData::class)) {
            continue;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[$class] = [$class];
    }

    ksort($classes);

    return $classes;
}

it('has the laravel-data provider booted, mirroring the host config', function () {
    // Guards the fix itself. Without the provider `config('data')` carries only the keys the
    // harness happens to set by hand, and the hydration path fatals rather than fails.
    expect(config('data'))->not->toBeNull();
    expect(config('data'))->toHaveKey('name_mapping_strategy');

    // And guards against the FALSE GREEN: the package default is `null`, only the host
    // (`~/Herd/splicewire-app/config/data.php`) sets the mapper.
    expect(config('data.name_mapping_strategy.input'))
        ->toBe(CamelCaseMapper::class);
});

it('finds declared Data classes to check', function () {
    // A discovery bug that silently found zero classes would make every case below vacuous.
    expect(count(requestLogsDataClasses()))->toBeGreaterThan(0);
});

it('can analyse and derive validation rules for every declared Data class', function (string $class) {
    $dataClass = app(DataConfig::class)->getDataClass($class);

    expect($dataClass->name)->toBe($class);

    $rules = $class::getValidationRules([]);

    expect($rules)->toBeArray();
})->with(requestLogsDataClasses());
