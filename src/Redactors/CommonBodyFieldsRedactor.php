<?php

namespace Rushing\RequestLogs\Redactors;

use Illuminate\Support\Str;

class CommonBodyFieldsRedactor extends DotNotationRedactor
{
    /**
     * The canonical secret body keys, written once in snake_case. Each is redacted in its snake AND camel spelling, at ANY
     * depth (top level included), in both the request and the response body, so a new secret is one entry here rather
     * than a hand-listed set of paths that drifts by casing or depth.
     */
    public const SECRET_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'refresh_token',
        'plain_text_token',
        'auth_token',
        'api_key',
        'secret',
        'client_secret',
        'private_key',
        'credentials',
        'otp',
        'recovery_code',
        'two_factor_secret',
    ];

    public function __construct(array $additionalPaths = [], string|\Closure $replacement = '[REDACTED]')
    {
        parent::__construct(
            array_merge(self::paths(), $additionalPaths),
            $replacement
        );
    }

    /**
     * `**` matches the key at the body's top level too.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $keys = [];
        foreach (self::SECRET_KEYS as $key) {
            $keys[$key] = true;
            $keys[Str::camel($key)] = true;
        }

        $paths = [];
        foreach (['request.body', 'response.body'] as $body) {
            foreach (array_keys($keys) as $key) {
                $paths[] = "{$body}.**.{$key}";
            }
        }

        return $paths;
    }
}
