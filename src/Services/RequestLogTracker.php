<?php

namespace Rushing\RequestLogs\Services;

use Illuminate\Support\Collection;

class RequestLogTracker
{
    /**
     * Models to be associated with request logs, keyed by correlation/request ID.
     */
    protected array $modelsByRequest = [];

    /**
     * Add a model to be associated with a request log.
     */
    public function addModel(string $requestId, string $modelClass, string $modelId): void
    {
        if (! isset($this->modelsByRequest[$requestId])) {
            $this->modelsByRequest[$requestId] = [];
        }

        // Add the model to the collection if it's not already there.
        $key = "{$modelClass}:{$modelId}";
        if (! isset($this->modelsByRequest[$requestId][$key])) {
            $this->modelsByRequest[$requestId][$key] = [
                'model_class' => $modelClass,
                'model_id' => $modelId,
            ];
        }
    }

    /**
     * Get all models associated with a specific request ID.
     */
    public function getModelsForRequest(string $requestId): Collection
    {
        if (! isset($this->modelsByRequest[$requestId])) {
            return new Collection;
        }

        return collect($this->modelsByRequest[$requestId]);
    }

    /**
     * Get all request IDs tracked by this service.
     */
    public function getRequestIds(): array
    {
        return array_keys($this->modelsByRequest);
    }

    /**
     * Clear all models for a specific request ID.
     */
    public function clearRequest(string $requestId): void
    {
        if (isset($this->modelsByRequest[$requestId])) {
            unset($this->modelsByRequest[$requestId]);
        }
    }

    /**
     * Clear all tracked models for all requests.
     */
    public function clearAll(): void
    {
        $this->modelsByRequest = [];
    }
}
