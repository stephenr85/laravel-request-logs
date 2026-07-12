<?php

namespace Rushing\RequestLogs\Redactors;

class CommonHeaderFieldsRedactor extends DotNotationRedactor
{
    public function __construct(array $additionalPaths = [], string|\Closure $replacement = '[REDACTED]')
    {
        $commonHeaderPaths = [
            'request.headers.authorization',
            'request.headers.cookie',
            'request.headers.x-csrf-token',
            'request.headers.x-xsrf-token',
            'request.headers.x-api-key',
            'request.headers.x-auth-token',
            'response.headers.set-cookie',
            'response.headers.authorization',
            'response.headers.www-authenticate',
        ];

        parent::__construct(
            array_merge($commonHeaderPaths, $additionalPaths),
            $replacement
        );
    }
}
