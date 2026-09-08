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

        // Request-scoped tracking / collection services — and `scoped()`, not `singleton()`, so the
        // comment and the binding agree.
        //
        // Under request-per-process PHP the two are indistinguishable: the container dies with the
        // request. Under a persistent worker (Octane/FrankenPHP, and the QUEUE WORKER, which is one
        // already) Laravel calls `forgetScopedInstances()` between units of work, so a `scoped()`
        // binding is rebuilt and a `singleton()` is carried forward.
        //
        // ⚠️ For the COLLECTOR this is a correctness bug, not merely retention.
        // `$terminatingCallbackRegistered` is a sticky boolean: request 1 registers the flush callback
        // and sets it true; request 2 onward sees `true`, registers NOTHING, and `$pendingLogs`
        // accumulates without ever flushing. Silent loss of request logs, in the package whose entire
        // job is not losing them.
        //
        // For the TRACKER it is retention. `$modelsByRequest` is keyed by request id, so it never
        // returns the wrong request's models — it simply never forgets any of them, growing for the
        // life of the worker. Correct-by-key and leaking anyway; the correctness probe passes it.
        $this->app->scoped(RequestLogTracker::class);
        $this->app->scoped(RequestLogCollector::class);

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
