<?php

namespace Rushing\RequestLogs\Processors;

use Illuminate\Pipeline\Pipeline;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RequestLogProcessor implements ProcessorInterface
{
    /**
     * Create a new processor instance.
     */
    public function __construct(
        protected array $redactorConfigs = []
    ) {}

    /**
     * Process the log record.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Only process if the context is an array of data.
        if (! isset($record->context) || ! is_array($record->context)) {
            return $record;
        }

        // Process the context data through the redaction pipeline.
        $processedContext = $this->processData($record->context, $this->redactorConfigs);

        // Return a new record with the processed context.
        return $record->with(context: $processedContext);
    }

    /**
     * Process data through a pipeline of redactors.
     */
    protected function processData(array $data, array $redactorConfigs): array
    {
        if (empty($redactorConfigs)) {
            return $data;
        }

        $redactors = $this->resolveRedactors($redactorConfigs);

        return app(Pipeline::class)
            ->send($data)
            ->through($redactors)
            ->thenReturn();
    }

    /**
     * Resolve redactor configurations into instances.
     */
    protected function resolveRedactors(array $redactorConfigs): array
    {
        return array_map(function ($config) {
            if (is_string($config)) {
                // Simple class name — instantiate with default args.
                return new $config;
            }

            if (is_array($config)) {
                // [class, ...args] format.
                $class = array_shift($config);

                return new $class(...$config);
            }

            if (is_object($config)) {
                // Already instantiated.
                return $config;
            }

            throw new \InvalidArgumentException('Invalid redactor configuration');
        }, $redactorConfigs);
    }
}
