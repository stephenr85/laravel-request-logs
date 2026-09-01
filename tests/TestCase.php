<?php

namespace Rushing\RequestLogs\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;
use Rushing\RequestLogs\RequestLogsServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            // Testbench does NOT auto-discover: a package harness boots exactly what this
            // method names, while `src/` freely imports anything it can autoload. This package
            // requires `spatie/laravel-data` and ships `Data\RequestLogData`, but never booted
            // its provider — so `config('data')` held only the seven keys hand-rolled below and
            // every unset key (`name_mapping_strategy`, `structure_caching`, `features`, …) was
            // missing. Measured in `splicewire/tower` as a FATAL (`config('data')` null →
            // "Trying to access array offset on null" inside `validateAndCreate()`); here the
            // hand-rolled partial only masked it. Booting the provider merges the package
            // defaults UNDER the explicit sets below, so those still win.
            // api-surface-coherence tickets 84/85.
            LaravelDataServiceProvider::class,

            // Same reason, second instance: `RequestLogPipelineManager` declares `#[IsRegistry]` and
            // the RegistryIndex binding lives in `rushing/laravel-popcorn`, which testbench will not
            // discover. Without this the index is auto-resolvable but UNSHARED, so every index
            // assertion in this package would pass over a throwaway object. registry-kernel 38.
            PopcornServiceProvider::class,

            RequestLogsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach ([
            'create_request_logs_table',
            'create_request_log_models_table',
        ] as $name) {
            $migration = require __DIR__."/../database/migrations/{$name}.php.stub";
            $migration->up();
        }
    }

    protected function getEnvironmentSetUp($app)
    {
        // Default database — in-memory sqlite.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Configure spatie/laravel-data. This block USED to hand-roll seven `data.*` keys as a
        // manual stand-in for the config no provider was supplying — and it diverged from the host
        // in three of them, most damagingly `rule_inferrers => []`, which meant NOT ONE validation
        // rule was ever inferred here and `getValidationRules()` returned near-empty arrays that
        // read as passing. That is the same false green ticket 84 named, wearing a second face:
        // the harness was not merely missing config, it was actively SHADOWING the provider's with
        // a worse reconstruction. Now that LaravelDataServiceProvider boots, the reconstruction is
        // deleted and only the two deliberate deltas below remain. api-surface-coherence 85.
        //
        // `validation_strategy => 'always'` went with it, and that one is worth naming. Both the
        // host and the package default are `OnlyRequests`; `always` was a stricter regime the
        // harness invented and NO host runs. It was inert only because `rule_inferrers` was empty
        // — restore the inferrers and it fails six pre-existing tests on `from()` calls that are
        // perfectly legal in production. Two wrong settings cancelling out is not a green suite.

        // Booting LaravelDataServiceProvider alone would be a FALSE GREEN. The package ships
        // `name_mapping_strategy.input => null` (measured here before the fix), but the only
        // host that runs this code (`~/Herd/splicewire-app/config/data.php`) sets it to
        // CamelCaseMapper — the one semantic delta between the two files. A DTO with snake_case
        // payload keys hydrates fine under the package default and silently stops mapping under
        // the host's mapper. The harness mirrors the host, not the package default.
        $app['config']->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        // Structure caching points at `app_path('Data')`, which does not exist here, and a
        // cached reflection analysis carried across runs is exactly what a harness should not have.
        $app['config']->set('data.structure_caching.enabled', false);

        // Configure the request-log channels.
        $app['config']->set('request-logs.channels', [
            'test_raw' => [],
            'test_redacted' => [
                CommonHeaderFieldsRedactor::class,
                CommonBodyFieldsRedactor::class,
            ],
        ]);

        // Configure log channels for testing.
        $app['config']->set('logging.channels.test_raw', [
            'driver' => 'array',
        ]);
        $app['config']->set('logging.channels.test_redacted', [
            'driver' => 'array',
        ]);
    }
}
