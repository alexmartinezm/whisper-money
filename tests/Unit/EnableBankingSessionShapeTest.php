<?php

use App\Services\Banking\EnableBankingSessionShape;

test('it describes an authorized session that carries no accounts', function () {
    $shape = EnableBankingSessionShape::describe([
        'session_id' => 'session-secret-123',
        'status' => 'AUTHORIZED',
        'accounts' => [],
        'accounts_data' => [],
        'aspsp' => ['name' => 'Trade Republic', 'country' => 'ES'],
        'access' => ['valid_until' => '2026-11-24T13:19:41Z', 'balances' => true, 'transactions' => true],
    ]);

    expect($shape)->toMatchArray([
        'session_status' => 'AUTHORIZED',
        'has_session_id' => true,
        'accounts_count' => 0,
        'accounts_entry_type' => null,
        'accounts_data_count' => 0,
        'accounts_data_keys' => null,
        'access_valid_until' => '2026-11-24T13:19:41Z',
        'aspsp_name' => 'Trade Republic',
        'aspsp_country' => 'ES',
    ]);

    expect($shape['keys'])->toContain('session_id', 'accounts', 'accounts_data')
        ->and($shape['access_keys'])->toBe(['valid_until', 'balances', 'transactions']);
});

test('it never writes down an identifier', function () {
    $shape = EnableBankingSessionShape::describe([
        'session_id' => 'session-secret-123',
        'status' => 'AUTHORIZED',
        'accounts' => ['uid-secret-456'],
        'accounts_data' => [[
            'uid' => 'uid-secret-456',
            'identification_hash' => 'hash-secret-789',
            'account_id' => ['iban' => 'ES1234567890'],
            'name' => 'A Real Person',
        ]],
    ]);

    // The whole point of the class: the field names survive, the values do not.
    expect($shape['accounts_data_keys'])->toBe(['uid', 'identification_hash', 'account_id', 'name'])
        ->and(json_encode($shape))
        ->not->toContain('session-secret-123')
        ->not->toContain('uid-secret-456')
        ->not->toContain('hash-secret-789')
        ->not->toContain('ES1234567890')
        ->not->toContain('A Real Person');
});

test('it reports how the account entries arrived', function (array $accounts, ?string $expected) {
    expect(EnableBankingSessionShape::describe(['accounts' => $accounts])['accounts_entry_type'])
        ->toBe($expected);
})->with([
    'bare uid strings' => [['uid-a', 'uid-b'], 'string'],
    'account objects' => [[['uid' => 'uid-a']], 'object'],
    'one of each' => [['uid-a', ['uid' => 'uid-b']], 'mixed'],
    'neither' => [[42], 'other'],
    'none at all' => [[], null],
]);

test('it survives a body missing every key it looks for', function () {
    $shape = EnableBankingSessionShape::describe([]);

    expect($shape)->toMatchArray([
        'session_status' => null,
        'keys' => [],
        'has_session_id' => false,
        'accounts_count' => 0,
        'accounts_entry_type' => null,
        'accounts_data_count' => 0,
        'accounts_data_keys' => null,
        'access_keys' => [],
        'access_valid_until' => null,
        'aspsp_name' => null,
        'aspsp_country' => null,
    ]);
});

test('it does not trip over a body whose fields are the wrong type', function () {
    $shape = EnableBankingSessionShape::describe([
        'session_id' => ['not', 'a', 'string'],
        'status' => 99,
        'accounts' => 'not-a-list',
        'accounts_data' => null,
        'access' => 'not-an-object',
        'aspsp' => ['name' => 42, 'country' => null],
    ]);

    expect($shape)->toMatchArray([
        'session_status' => null,
        'has_session_id' => false,
        'accounts_count' => 0,
        'accounts_data_count' => 0,
        'access_keys' => [],
        'aspsp_name' => null,
        'aspsp_country' => null,
    ]);
});
