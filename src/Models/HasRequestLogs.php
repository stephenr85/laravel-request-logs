<?php

namespace Rushing\RequestLogs\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\App;
use Rushing\RequestLogs\Services\RequestLogTracker;

trait HasRequestLogs
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootHasRequestLogs()
    {
        // Register a saved event to track models for association with request logs.
        static::saved(function ($model) {
            $model->registerWithRequestLogTracker();
        });
    }

    /**
     * Get all request logs associated with this model.
     */
    public function requestLogs(): MorphToMany
    {
        $requestLogModel = config('request-logs.models.request_log', RequestLog::class);

        return $this->morphToMany(
            $requestLogModel,
            'model',
            'request_log_models'
        );
    }

    /**
     * Get the most recent request log for this model.
     *
     * @return RequestLog|null
     */
    public function latestRequestLog()
    {
        return $this->requestLogs()->latest('request_at')->first();
    }

    /**
     * Register this model with the RequestLogTracker service when saved during
     * a correlated request.
     */
    protected function registerWithRequestLogTracker(): void
    {
        $request = request();

        // Only proceed if we're in a web context with a request.
        if (! $request) {
            return;
        }

        // Only track when a correlation key is present on the request.
        $correlationKey = $request->header(
            config('request-logs.correlation.header_name', 'X-Request-Id')
        );

        if (! $correlationKey) {
            return;
        }

        $tracker = App::make(RequestLogTracker::class);
        $tracker->addModel($correlationKey, get_class($this), $this->getKey());
    }
}
