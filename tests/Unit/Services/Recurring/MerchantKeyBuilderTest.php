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

it('drops a number from a merchant name but keeps a short alphanumeric one', function () {
    $builder = recurringMerchantKeyBuilder();

    // A year, a store number or a terminal id is the kind of thing that
    // differs between two charges of the same contract, so it cannot be part
    // of the identity. A short alphanumeric is a trading name — O2, M6 — and
    // dropping it would leave the charge with no identity at all.
    expect($builder->canonicalKey('ACME 2026'))->toBe('acme')
        ->and($builder->canonicalKey('ACME 2027'))->toBe('acme')
        ->and($builder->canonicalKey('O2 UK'))->toBe('o2 uk');
});

it('gives every PayPal reference format the same identity', function () {
    $builder = recurringMerchantKeyBuilder();

    // PayPal rewrites the reference on every charge and uses more than one
    // format for it. Thirteen charges of one subscription have to reach one
    // identity, or none of them ever reaches the three needed to be a series.
    $observed = [
        'PAYPAL *SPOTIFY 4F1A9B27',
        'PAYPAL *SPOTIFY 3E7C2D91',
        'PAYPAL *SPOTIFY 35314369001',
        'PAYPAL *SPOTIFY 35318822104',
        'PAYPAL *SPOTIFY 4029357733',
        'PAYPAL *SPOTIFY',
    ];

    $keys = array_unique(array_map(fn (string $value): string => $builder->canonicalKey($value), $observed));

    expect($keys)->toBe(['spotify']);
});

it('keeps a trading name and its full company name as one identity', function () {
    $builder = recurringMerchantKeyBuilder();

    // The same direct debit, before and after the bank started sending a
    // creditor name. Grouping still needs exact keys, so these two are not
    // equal — but reconciliation has to recognise them as one provider or the
    // series starts again from zero with the user's confirmation lost.
    expect($builder->shareIdentity('SEPA DD DIGI 9F8E7D6C', 'DIGI SPAIN TELECOM SLU'))->toBeTrue()
        ->and($builder->shareIdentity('RECIBO GENERALI 0501234567', 'GENERALI ESPANA SA'))->toBeTrue();
});

it('keeps two contracts at one provider apart', function () {
    $builder = recurringMerchantKeyBuilder();

    // Neither name contains the other, so containment cannot merge them.
    expect($builder->shareIdentity('GENERALI VIDA', 'GENERALI AUTO'))->toBeFalse()
        ->and($builder->shareIdentity('PAYPAL *SPOTIFY', 'PAYPAL *NETFLIX'))->toBeFalse();
});

it('does not treat a shared short token as a shared identity', function () {
    $builder = recurringMerchantKeyBuilder();

    expect($builder->shareIdentity('UK', 'UK GYM LIMITED'))->toBeFalse();
});

it('matches a counterparty to the current user without a hardcoded personal name', function () {
    $builder = recurringMerchantKeyBuilder();

    expect($builder->matchesCounterparty('TEST ACCOUNT HOLDER', 'Test Account Holder'))->toBeTrue()
        ->and($builder->matchesCounterparty('TEST ACCOUNT HOLDER', 'Other Person'))->toBeFalse();
});
