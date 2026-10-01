<?php

use App\Services\Banking\EnableBankingPsuContext;

it('only exposes a complete interactive request identity as EnableBanking headers', function () {
    expect(class_exists(EnableBankingPsuContext::class))->toBeTrue();

    $context = new EnableBankingPsuContext;
    $context->set('192.0.2.44', null);
    expect($context->headers())->toBe([]);

    $context->set(null, 'SyntheticBrowser/1.0');
    expect($context->headers())->toBe([]);

    $context->set('192.0.2.44', 'SyntheticBrowser/1.0');

    expect($context->headers())->toBe([
        'Psu-Ip-Address' => '192.0.2.44',
        'Psu-User-Agent' => 'SyntheticBrowser/1.0',
    ]);

    $context->clear();

    expect($context->headers())->toBe([]);
});
