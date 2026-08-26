<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('createSession recovers account details from the authorized session when the exchange returns none', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['POST', '/sessions'] => Http::response([
                'session_id' => 'session-123',
                'accounts' => [],
                'aspsp' => ['name' => 'Trade Republic', 'country' => 'ES'],
                'access' => ['valid_until' => '2026-11-24T13:19:41Z'],
            ]),
            ['GET', '/sessions/session-123'] => Http::response([
                'status' => 'AUTHORIZED',
                'accounts' => ['trade-account-123'],
                'accounts_data' => [
                    ['uid' => 'trade-account-123', 'identification_hash' => 'redacted'],
                ],
            ]),
            ['GET', '/accounts/trade-account-123/details'] => Http::response([
                'uid' => 'trade-account-123',
                'currency' => 'EUR',
                'name' => 'Trade Republic',
                'account_id' => ['iban' => 'ES1234567890'],
            ]),
            default => Http::response([], 404),
        };
    });

    $session = enableBankingProviderForTest()->createSession('authorization-code');

    expect($session['accounts'])->toBe([
        [
            'uid' => 'trade-account-123',
            'currency' => 'EUR',
            'name' => 'Trade Republic',
            'account_id' => ['iban' => 'ES1234567890'],
        ],
    ]);
    Http::assertSentCount(3);
});
