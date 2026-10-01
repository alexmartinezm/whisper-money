<?php

use App\Contracts\BankingProviderInterface;
use App\Services\Banking\EnableBankingPsuContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function syntheticEnableBankingKeyPath(): string
{
    $relativePath = 'storage/framework/cache/enablebanking-psu-'.bin2hex(random_bytes(4)).'.pem';
    $path = base_path($relativePath);

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $privateKey);
    file_put_contents($path, $privateKey);

    return $relativePath;
}

function assertSyntheticPsuHeaders(Request $request): void
{
    expect($request->toPsrRequest()->getHeaderLine('Psu-Ip-Address'))->toBe('192.0.2.44')
        ->and($request->toPsrRequest()->getHeaderLine('Psu-User-Agent'))->toBe('SyntheticBrowser/1.0');
}

afterEach(function () {
    app()->forgetInstance(EnableBankingPsuContext::class);
});

it('sends PSU identity on every paginated transaction and balance request', function () {
    $keyPath = syntheticEnableBankingKeyPath();
    config([
        'services.enablebanking.app_id' => 'synthetic-app-id',
        'services.enablebanking.private_key_path' => $keyPath,
    ]);

    $context = new EnableBankingPsuContext;
    $context->set('192.0.2.44', 'SyntheticBrowser/1.0');
    app()->instance(EnableBankingPsuContext::class, $context);

    Http::fake([
        'api.enablebanking.com/accounts/synthetic-account/transactions*' => Http::sequence()
            ->push(['transactions' => [], 'continuation_key' => 'synthetic-page-2'])
            ->push(['transactions' => [], 'continuation_key' => null]),
        'api.enablebanking.com/accounts/synthetic-account/balances' => Http::response([
            'balances' => [],
        ]),
    ]);

    $provider = app(BankingProviderInterface::class);
    $provider->getTransactions('synthetic-account', '2026-09-01', '2026-09-30');
    $provider->getTransactions('synthetic-account', '2026-09-01', '2026-09-30', 'synthetic-page-2');
    $provider->getBalances('synthetic-account');

    $requests = Http::recorded();

    expect($requests)->toHaveCount(3);

    foreach ($requests as [$request]) {
        assertSyntheticPsuHeaders($request);
    }
});
