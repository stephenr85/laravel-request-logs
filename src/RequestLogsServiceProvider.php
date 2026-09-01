<?php

namespace Rushing\RequestLogs;

use Illuminate\Support\Facades\Event;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\Http\Middleware\GuzzleRequestLogMiddleware;
use Rushing\RequestLogs\Http\Middleware\RequestLogMiddleware;
use Rushing\RequestLogs\Listeners\CompleteRequestLogListener;
use Rushing\RequestLogs\Services\RequestLogCollector;
use Rushing\RequestLogs\Services\RequestLogTracker;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RequestLogsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-request-logs')
            ->hasConfigFile('request-logs')
            ->hasMigration('create_request_logs_table')
            ->hasMigration('create_request_log_models_table');
    }

    public function packageRegistered(): void
    {
        // Inbound middleware is a singleton; the Guzzle middleware is not
        // (multiple concurrent outbound calls are possible).
        $this->app->singleton(RequestLogMiddleware::class);
        $this->app->bind(GuzzleRequestLogMiddleware::class);

        // Request-scoped tracking / collection services.
        $this->app->singleton(RequestLogTracker::class);
        $this->app->singleton(RequestLogCollector::class);

        // Register the configured data class so it can be resolved/overridden.
        $dataClass = config('request-logs.data.request_log_data', RequestLogData::class);
        $this->app->singleton($dataClass);

        // The pipeline manager loads channels and pushes redaction processors
        // onto the target Monolog channels at boot.
        $this->app->singleton(RequestLogPipelineManager::class, function ($app) {
            $manager = new RequestLogPipelineManager;

            $channels = $app['config']->get('request-logs.channels', []);
            $manager->loadChannels($channels, by: 'config:request-logs.channels');
            $manager->registerProcessors();

            return $manager;
        });
    }

    public function packageBooted(): void
    {
        Event::listen(
            CompleteRequestLogEvent::class,
            CompleteRequestLogListener::class
        );
    }
}
