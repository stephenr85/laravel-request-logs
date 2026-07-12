<?php

namespace Rushing\RequestLogs\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Services\RequestLogTracker;

class CompleteRequestLogEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $requestId  The correlation key / request ID
     * @param  string  $requestLogId  The ID of the RequestLog model
     * @param  RequestLogTracker  $tracker  The tracker instance with all models for this request
     * @param  RequestLogData|null  $requestLogData  The request log data to process through pipelines
     */
    public function __construct(
        public string $requestId,
        public string $requestLogId,
        public RequestLogTracker $tracker,
        public ?RequestLogData $requestLogData = null
    ) {}
}
