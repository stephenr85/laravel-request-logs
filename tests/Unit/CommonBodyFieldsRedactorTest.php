<?php

use Monolog\Level;
use Monolog\LogRecord;
use Rushing\RequestLogs\Data\RequestLogData;
use Rushing\RequestLogs\Processors\RequestLogProcessor;
use Rushing\RequestLogs\Redactors\CommonBodyFieldsRedactor;
use Rushing\RequestLogs\Redactors\CommonHeaderFieldsRedactor;

// A camelCase API answers a login with a NESTED bearer token ({data: {accessToken}}) and takes camel confirmation keys.
// The common body redactor must catch those at any depth under either body, not only top-level snake keys.

function loginExchange(): array
{
    return RequestLogData::instance([
        'id' => 'login-1',
        'operationName' => 'login',
        'url' => '/api/v1/login',
        'success' => true,
        'statusCode' => 200,
        'method' => 'POST',
        'request' => [
            'body' => [
                'email' => 'person@example.test',
                'password' => 'request-password',
                'passwordConfirmation' => 'request-confirmation',
                'profile' => ['security' => ['password' => 'nested-password', 'passwordConfirmation' => 'nested-confirmation']],
            ],
            'headers' => ['content-type' => 'application/json'],
        ],
        'response' => [
            'body' => [
                'data' => [
                    'accessToken' => 'access-token-value',
                    'refreshToken' => 'refresh-token-value',
                    'tokenType' => 'Bearer',
                    'user' => ['name' => 'Person', 'plainTextToken' => 'plain-text-token-value'],
                    'session' => ['token' => 'nested-token-value'],
                ],
            ],
            'headers' => ['content-type' => 'application/json'],
        ],
    ])->toArray();
}

it('redacts a login response\'s nested camel tokens and nested camel password keys', function () {
    $result = (new CommonBodyFieldsRedactor)->handle(loginExchange(), fn ($data) => $data);

    expect(data_get($result, 'response.body.data.accessToken'))->toBe('[REDACTED]')
        ->and(data_get($result, 'response.body.data.refreshToken'))->toBe('[REDACTED]')
        ->and(data_get($result, 'response.body.data.user.plainTextToken'))->toBe('[REDACTED]')
        ->and(data_get($result, 'response.body.data.session.token'))->toBe('[REDACTED]')
        ->and(data_get($result, 'request.body.password'))->toBe('[REDACTED]')
        ->and(data_get($result, 'request.body.passwordConfirmation'))->toBe('[REDACTED]')
        ->and(data_get($result, 'request.body.profile.security.password'))->toBe('[REDACTED]')
        ->and(data_get($result, 'request.body.profile.security.passwordConfirmation'))->toBe('[REDACTED]');

    // What is not a secret stays readable.
    expect(data_get($result, 'response.body.data.tokenType'))->toBe('Bearer')
        ->and(data_get($result, 'response.body.data.user.name'))->toBe('Person')
        ->and(data_get($result, 'request.body.email'))->toBe('person@example.test');
});

it('keeps no secret value anywhere in a channel\'s processed log context (header + body redactor stack)', function () {
    $processor = new RequestLogProcessor([CommonHeaderFieldsRedactor::class, CommonBodyFieldsRedactor::class]);
    $record = new LogRecord(new DateTimeImmutable, 'request_logs', Level::Info, 'POST /api/v1/login', loginExchange());

    $context = json_encode($processor($record)->context);

    foreach (['access-token-value', 'refresh-token-value', 'plain-text-token-value', 'nested-token-value', 'request-password', 'request-confirmation', 'nested-password', 'nested-confirmation'] as $secret) {
        expect($context)->not->toContain($secret);
    }
    expect($context)->toContain('Bearer');
});
