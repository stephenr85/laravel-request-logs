<?php

namespace Rushing\RequestLogs\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;
use Rushing\RequestLogs\RequestLogsServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Normalizers\ArrayNormalizer;

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

        // Configure spatie/laravel-data.
        $app['config']->set('data.validation_strategy', 'always');
        $app['config']->set('data.normalizers', [
            ArrayNormalizer::class,
        ]);
        $app['config']->set('data.transformers', []);
        $app['config']->set('data.casts', []);
        $app['config']->set('data.rule_inferrers', []);
        $app['config']->set('data.max_transformation_depth', 512);
        $app['config']->set('data.throw_when_max_transformation_depth_reached', true);

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
