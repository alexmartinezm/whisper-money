<?php

use App\Models\Transaction;
use App\Services\Ai\DescriptionTokenizer;
use App\Services\Recurring\MerchantKeyBuilder;

function recurringMerchantKeyBuilder(): MerchantKeyBuilder
{
    return new MerchantKeyBuilder(new DescriptionTokenizer);
}

it('removes only a recognised PayPal reference suffix from a merchant key', function () {
    $builder = recurringMerchantKeyBuilder();

    $spotify = new Transaction([
        'amount' => -2099,
        'creditor_name' => 'PAYPAL *SPOTIFY 4F1A9B27',
        'description' => 'PAYPAL *SPOTIFY 4F1A9B27',
    ]);
    $familyPlan = new Transaction([
        'amount' => -2099,
        'creditor_name' => 'PAYPAL *SPOTIFY FAMILY PLAN',
        'description' => 'PAYPAL *SPOTIFY FAMILY PLAN',
    ]);

    expect($builder->keyFor($spotify, [], 0.0))
        ->toBe(['creditor_name', 'spotify'])
        ->and($builder->keyFor($familyPlan, [], 0.0))
        ->toBe(['creditor_name', 'family plan spotify']);
});

it('shares a stable key when a creditor name appears after description-only history', function () {
    $builder = recurringMerchantKeyBuilder();

    $descriptionOnly = new Transaction([
        'amount' => -1299,
        'creditor_name' => null,
        'description' => 'SEPA DD DIGI 9F8E7D6C',
    ]);
    $creditorPopulated = new Transaction([
        'amount' => -1299,
        'creditor_name' => 'DIGI',
        'description' => 'SEPA DD DIGI 1A2B3C4D',
    ]);

    expect($builder->stableKeyFor($descriptionOnly, [], 0.0))
        ->toBe('digi')
        ->and($builder->stableKeyFor($creditorPopulated, [], 0.0))
        ->toBe('digi');
});

it('retains distinct merchant names that only share a payment processor', function () {
    $builder = recurringMerchantKeyBuilder();

    $first = new Transaction([
        'amount' => -1299,
        'creditor_name' => 'PAYPAL *SPOTIFY',
        'description' => 'PAYPAL *SPOTIFY',
    ]);
    $second = new Transaction([
        'amount' => -1299,
        'creditor_name' => 'PAYPAL *NETFLIX',
        'description' => 'PAYPAL *NETFLIX',
    ]);

    expect($builder->stableKeyFor($first, [], 0.0))
        ->toBe('spotify')
        ->and($builder->stableKeyFor($second, [], 0.0))
        ->toBe('netflix');
});

it('preserves merchant numbers that are not recognised processor references', function () {
    $builder = recurringMerchantKeyBuilder();
    $merchant = new Transaction([
        'amount' => -1299,
        'creditor_name' => 'ACME 2026',
        'description' => 'ACME 2026',
    ]);

    expect($builder->stableKeyFor($merchant, [], 0.0))->toBe('2026 acme');
});

it('matches a counterparty to the current user without a hardcoded personal name', function () {
    $builder = recurringMerchantKeyBuilder();

    expect($builder->matchesCounterparty('TEST ACCOUNT HOLDER', 'Test Account Holder'))->toBeTrue()
        ->and($builder->matchesCounterparty('TEST ACCOUNT HOLDER', 'Other Person'))->toBeFalse();
});
