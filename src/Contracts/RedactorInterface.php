<?php

namespace Rushing\RequestLogs\Contracts;

interface RedactorInterface
{
    /**
     * Process and potentially redact the given data.
     *
     * @param  mixed  $data  The data to process
     * @param  \Closure  $next  The next item in the pipeline
     * @return mixed The processed data
     */
    public function handle(mixed $data, \Closure $next): mixed;
}
