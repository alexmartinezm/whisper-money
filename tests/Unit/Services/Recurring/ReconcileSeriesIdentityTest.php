<?php

use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Services\Ai\DescriptionTokenizer;
use App\Services\Recurring\MerchantKeyBuilder;
use App\Services\Recurring\ReconcileSeriesIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

function recurringIdentityService(): ReconcileSeriesIdentity
{
    return new ReconcileSeriesIdentity(new MerchantKeyBuilder(new DescriptionTokenizer));
}

function recurringIdentityCandidate(string $accountId): array
{
    return [
        'stable_key' => 'spotify',
        'identity_aliases' => ['spotify', 'paypal spotify'],
        'match_field' => 'creditor_name',
        'merchant_key' => 'spotify',
        'direction' => 'expense',
        'currency_code' => 'EUR',
        'account_id' => $accountId,
        'first_occurred_on' => CarbonImmutable::parse('2026-06-05'),
        'last_occurred_on' => CarbonImmutable::parse('2026-09-05'),
        'interval_days' => 30,
        'transaction_ids' => ['transaction-1', 'transaction-2', 'transaction-3', 'transaction-4'],
        'cadence' => 'monthly',
        'expected_amount' => -2099,
        'amount_is_variable' => false,
        'display_name' => 'Spotify',
        'occurrence_count' => 4,
        'next_expected_on' => CarbonImmutable::parse('2026-10-05'),
    ];
}

function recurringExistingSeries(string $id, string $accountId, ?string $deletedAt = null): RecurringSeries
{
    $series = new RecurringSeries;
    $series->setRawAttributes([
        'id' => $id,
        'identity_key' => 'spotify',
        'identity_aliases' => ['paypal spotify'],
        'match_field' => 'description',
        'merchant_key' => 'paypal spotify',
        'direction' => 'expense',
        'currency_code' => 'EUR',
        'account_id' => $accountId,
        'first_occurred_on' => CarbonImmutable::parse('2026-06-05'),
        'last_occurred_on' => CarbonImmutable::parse('2026-09-05'),
        'interval_days' => 30,
        'cadence' => 'monthly',
        'expected_amount' => -2099,
        'amount_is_variable' => false,
        'display_name' => 'My streaming',
        'occurrence_count' => 4,
        'next_expected_on' => CarbonImmutable::parse('2026-10-05'),
        'user_state' => RecurringSeriesUserState::Ignored,
        'deleted_at' => $deletedAt,
    ], true);

    return $series;
}

it('matches the account-specific contract instead of merging same-provider contracts', function () {
    $service = recurringIdentityService();
    $candidate = recurringIdentityCandidate('account-a');
    $existing = new Collection([
        recurringExistingSeries('series-a', 'account-a'),
        recurringExistingSeries('series-b', 'account-b'),
    ]);

    $plan = $service->planCandidate($candidate, $existing);

    expect($plan['action'])->toBe('merged')
        ->and($plan['series']->getAttribute('id'))->toBe('series-a')
        ->and($plan['reason'])->toBe('stable_identity');
});

it('requires review when a changed account could match more than one contract', function () {
    $service = recurringIdentityService();
    $candidate = recurringIdentityCandidate('account-c');
    $existing = new Collection([
        recurringExistingSeries('series-a', 'account-a'),
        recurringExistingSeries('series-b', 'account-b'),
    ]);

    $plan = $service->planCandidate($candidate, $existing);

    expect($plan['action'])->toBe('review_required')
        ->and($plan['series'])->toBeNull()
        ->and($plan['reason'])->toBe('ambiguous_account_change');
});

it('does not resurrect a deleted identity automatically', function () {
    $service = recurringIdentityService();
    $candidate = recurringIdentityCandidate('account-a');
    $existing = new Collection([
        recurringExistingSeries('series-a', 'account-a', '2026-09-10 12:00:00'),
    ]);

    $plan = $service->planCandidate($candidate, $existing);

    expect($plan['action'])->toBe('review_required')
        ->and($plan['series'])->toBeNull()
        ->and($plan['reason'])->toBe('deleted_identity');
});

it('keeps an ignored series eligible for derived refresh without changing its decision', function () {
    $service = recurringIdentityService();
    $candidate = recurringIdentityCandidate('account-a');
    $existing = new Collection([
        recurringExistingSeries('series-a', 'account-a'),
    ]);

    $plan = $service->planCandidate($candidate, $existing);

    expect($plan['series']->getAttribute('user_state'))->toBe(RecurringSeriesUserState::Ignored)
        ->and($plan['action'])->not->toBe('review_required');
});
