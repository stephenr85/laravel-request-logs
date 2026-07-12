<?php

namespace Rushing\RequestLogs\Redactors;

class CommonBodyFieldsRedactor extends DotNotationRedactor
{
    public function __construct(array $additionalPaths = [], string|\Closure $replacement = '[REDACTED]')
    {
        $commonBodyPaths = [
            // Request body fields
            'request.body.password',
            'request.body.password_confirmation',
            'request.body.token',
            'request.body.access_token',
            'request.body.refresh_token',
            'request.body.api_key',
            'request.body.secret',
            'request.body.private_key',
            'request.body.credentials',
            'request.body.auth_token',

            // Response body fields
            'response.body.password',
            'response.body.token',
            'response.body.access_token',
            'response.body.refresh_token',
            'response.body.api_key',
            'response.body.secret',
            'response.body.private_key',
            'response.body.credentials',
            'response.body.auth_token',

            // Nested patterns with wildcards
            'request.body.*.password',
            'request.body.*.token',
            'request.body.*.api_key',
            'response.body.*.password',
            'response.body.*.token',
            'response.body.*.api_key',
        ];

        parent::__construct(
            array_merge($commonBodyPaths, $additionalPaths),
            $replacement
        );
    }
}
