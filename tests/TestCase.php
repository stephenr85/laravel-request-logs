<?php

namespace Rushing\RequestLogs\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;
use Rushing\RequestLogs\RequestLogsServiceProvider;
use Spatie\LaravelData\Normalizers\ArrayNormalizer;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
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
