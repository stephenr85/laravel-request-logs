<?php

namespace Rushing\RequestLogs\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Events\CompleteRequestLogEvent;
use Rushing\RequestLogs\RequestLogPipelineManager;

class CompleteRequestLogListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(CompleteRequestLogEvent $event): void
    {
        $requestLogClass = config('request-logs.models.request_log');

        // Find the request log record by its ID (not request_id).
        $requestLog = $requestLogClass::find($event->requestLogId);

        if (! $requestLog) {
            Log::warning("Request log with ID {$event->requestLogId} not found");

            return;
        }

        // Collect all models for a bulk insert.
        $modelsByClass = [];
        $requestIds = $event->tracker->getRequestIds();

        foreach ($requestIds as $requestId) {
            $models = $event->tracker->getModelsForRequest($requestId);

            foreach ($models as $modelData) {
                $modelClass = $modelData['model_class'];
                $modelId = $modelData['model_id'];

                if (! isset($modelsByClass[$modelClass])) {
                    $modelsByClass[$modelClass] = [];
                }

                // Add to collection, avoiding duplicates.
                if (! in_array($modelId, $modelsByClass[$modelClass])) {
                    $modelsByClass[$modelClass][] = $modelId;
                }
            }
        }

        // Build bulk insert data for all model types at once.
        $bulkInsertData = [];
        $now = now();

        foreach ($modelsByClass as $modelClass => $modelIds) {
            try {
                // Verify models exist before attaching.
                $keyName = $modelClass::make()->getKeyName();
                $existingIds = $modelClass::whereIn($keyName, $modelIds)->pluck($keyName)->toArray();

                if (empty($existingIds)) {
                    Log::warning("No models found for {$modelClass} with IDs: ".implode(', ', $modelIds));

                    continue;
                }

                // Log missing models.
                $missingIds = array_diff($modelIds, $existingIds);
                if (! empty($missingIds)) {
                    Log::warning("Models not found for {$modelClass} with IDs: ".implode(', ', $missingIds));
                }

                // Get the morph type from the morph map or fall back to the class name.
                $morphType = array_search($modelClass, Relation::morphMap()) ?: $modelClass;

                // Prepare bulk insert data for this model class.
                foreach ($existingIds as $modelId) {
                    $bulkInsertData[] = [
                        'request_log_id' => $requestLog->id,
                        'model_type' => $morphType,
                        'model_id' => (string) $modelId, // Cast to string to handle both UUID and non-UUID PKs.
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Error preparing models for bulk association: '.$e->getMessage(), [
                    'request_id' => $event->requestId,
                    'model_class' => $modelClass,
                    'model_ids' => $modelIds,
                    'exception' => $e,
                ]);
            }
        }

        // Single bulk upsert for ALL model associations at once.
        if (! empty($bulkInsertData)) {
            try {
                // Use the RequestLog model's connection to respect the configured
                // connection / multi-tenancy.
                $requestLog->getConnection()->table('request_log_models')->upsert(
                    $bulkInsertData,
                    ['request_log_id', 'model_type', 'model_id'], // Unique keys
                    ['updated_at'] // Update the timestamp on conflict
                );
            } catch (\Exception $e) {
                Log::error('Error bulk upserting model associations: '.$e->getMessage(), [
                    'request_id' => $event->requestId,
                    'exception' => $e,
                ]);
            }
        }

        // Clear the tracker after processing all requests.
        $event->tracker->clearAll();

        // Log the request data through the configured pipelines.
        if ($event->requestLogData) {
            try {
                $pipelineManager = app(RequestLogPipelineManager::class);
                $pipelineManager->log($event->requestLogData);
            } catch (\Exception $e) {
                Log::error('Error logging request data through pipelines: '.$e->getMessage(), [
                    'request_id' => $event->requestId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
