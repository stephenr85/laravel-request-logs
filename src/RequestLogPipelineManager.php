<?php

namespace Rushing\RequestLogs;

use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Processors\RequestLogProcessor;

class RequestLogPipelineManager
{
    protected array $channels = [];

    /**
     * Load channels from configuration.
     *
     * @param  array  $channelConfig  ['channel_name' => [redactors...], ...]
     * @return $this
     */
    public function loadChannels(array $channelConfig): self
    {
        $this->channels = $channelConfig;

        return $this;
    }

    /**
     * Add a single channel with redactors.
     *
     * @return $this
     */
    public function addChannel(string $channelName, array $redactors = []): self
    {
        $this->channels[$channelName] = $redactors;

        return $this;
    }

    /**
     * Register redaction processors on the configured log channels.
     */
    public function registerProcessors(): void
    {
        foreach ($this->channels as $channelName => $redactors) {
            $processor = new RequestLogProcessor($redactors);
            Log::channel($channelName)?->getLogger()->pushProcessor($processor);
        }
    }

    /**
     * Log a RequestLogData through all configured channels.
     */
    public function log(RequestLogData $data): void
    {
        $context = $data->toArray();

        foreach ($this->channels as $channelName => $redactors) {
            Log::channel($channelName)?->info($data->method.' '.$data->url, $context);
        }
    }

    /**
     * Get all configured channels.
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Clear all configured channels.
     *
     * @return $this
     */
    public function clearChannels(): self
    {
        $this->channels = [];

        return $this;
    }
}
