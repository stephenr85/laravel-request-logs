<?php

use Rushing\RequestLogs\Redactors\DotNotationRedactor;

it('redacts simple paths', function () {
    $redactor = new DotNotationRedactor(['request.body.password', 'response.headers.authorization']);

    $data = [
        'request' => [
            'body' => ['username' => 'john', 'password' => 'secret123'],
            'headers' => ['content-type' => 'application/json'],
        ],
        'response' => [
            'body' => ['id' => 123],
            'headers' => ['authorization' => 'Bearer token456'],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['request']['body']['password'])->toBe('[REDACTED]');
    expect($result['request']['body']['username'])->toBe('john');
    expect($result['response']['headers']['authorization'])->toBe('[REDACTED]');
    expect($result['response']['body']['id'])->toBe(123);
});

it('redacts wildcard paths', function () {
    $redactor = new DotNotationRedactor(['request.body.*.password']);

    $data = [
        'request' => [
            'body' => [
                'user1' => ['username' => 'john', 'password' => 'secret1'],
                'user2' => ['username' => 'jane', 'password' => 'secret2'],
            ],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['request']['body']['user1']['password'])->toBe('[REDACTED]');
    expect($result['request']['body']['user2']['password'])->toBe('[REDACTED]');
    expect($result['request']['body']['user1']['username'])->toBe('john');
    expect($result['request']['body']['user2']['username'])->toBe('jane');
});

it('uses custom replacement', function () {
    $redactor = new DotNotationRedactor(['request.body.token'], '***HIDDEN***');

    $data = [
        'request' => [
            'body' => ['token' => 'abc123'],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['request']['body']['token'])->toBe('***HIDDEN***');
});

it('uses callback replacement', function () {
    $redactor = new DotNotationRedactor(
        ['request.body.password'],
        fn ($value, $path, $context) => '['.strlen($value).' chars]'
    );

    $data = [
        'request' => [
            'body' => ['password' => 'secret123'],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['request']['body']['password'])->toBe('[9 chars]');
});

it('ignores missing paths', function () {
    $redactor = new DotNotationRedactor(['request.body.nonexistent']);

    $data = [
        'request' => [
            'body' => ['username' => 'john'],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['request']['body'])->toBe(['username' => 'john']);
});

it('redacts with deep wildcard at any level', function () {
    $redactor = new DotNotationRedactor(['**.card.number'], '[REDACTED]');

    $data = [
        'response' => [
            'body' => [
                'data' => [
                    'transactions' => [
                        [
                            'payment' => [
                                'billing' => [
                                    'card' => [
                                        'number' => '4111111111111111',
                                        'expiry' => [
                                            'month' => 12,
                                            'year' => 25,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'payment' => [
                                'billing' => [
                                    'card' => [
                                        'number' => '5555555555554444',
                                        'expiry' => [
                                            'month' => 1,
                                            'year' => 26,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['response']['body']['data']['transactions'][0]['payment']['billing']['card']['number'])
        ->toBe('[REDACTED]');
    expect($result['response']['body']['data']['transactions'][1]['payment']['billing']['card']['number'])
        ->toBe('[REDACTED]');

    // Should not redact expiry.
    expect($result['response']['body']['data']['transactions'][0]['payment']['billing']['card']['expiry']['month'])
        ->toBe(12);
});

it('redacts multiple deep wildcard paths', function () {
    $redactor = new DotNotationRedactor(['**.card.number', '**.card.expiry'], '[REDACTED]');

    $data = [
        'level1' => [
            'level2' => [
                'card' => [
                    'number' => '4111111111111111',
                    'expiry' => ['month' => 12, 'year' => 25],
                ],
            ],
        ],
        'other' => [
            'nested' => [
                'deep' => [
                    'card' => [
                        'number' => '5555555555554444',
                        'expiry' => ['month' => 1, 'year' => 26],
                    ],
                ],
            ],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['level1']['level2']['card']['number'])->toBe('[REDACTED]');
    expect($result['level1']['level2']['card']['expiry'])->toBe('[REDACTED]');
    expect($result['other']['nested']['deep']['card']['number'])->toBe('[REDACTED]');
    expect($result['other']['nested']['deep']['card']['expiry'])->toBe('[REDACTED]');
});

it('works with single wildcard and deep wildcard together', function () {
    $redactor = new DotNotationRedactor(['transactions.*.payment.billing.card.number', '**.card.cvv'], '[REDACTED]');

    $data = [
        'transactions' => [
            [
                'payment' => [
                    'billing' => [
                        'card' => [
                            'number' => '4111111111111111',
                            'cvv' => '123',
                        ],
                    ],
                ],
            ],
        ],
        'deep' => [
            'nested' => [
                'card' => [
                    'cvv' => '456',
                ],
            ],
        ],
    ];

    $result = $redactor->handle($data, fn ($data) => $data);

    expect($result['transactions'][0]['payment']['billing']['card']['number'])->toBe('[REDACTED]');
    expect($result['transactions'][0]['payment']['billing']['card']['cvv'])->toBe('[REDACTED]');
    expect($result['deep']['nested']['card']['cvv'])->toBe('[REDACTED]');
});
