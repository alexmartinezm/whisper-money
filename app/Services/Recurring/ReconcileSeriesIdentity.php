<?php

namespace App\Services\Recurring;

use App\Models\RecurringSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves a detected candidate against persisted series without making a
 * destructive identity decision. Matching is exact first and conservative when
 * an account changed or several contracts could explain the same observations.
 */
class ReconcileSeriesIdentity
{
    public function __construct(private readonly MerchantKeyBuilder $merchantKeys) {}

    /**
     * @param  array<string, mixed>  $candidate
     * @param  Collection<int, RecurringSeries>  $existing
     * @return array{action: string, series: RecurringSeries|null, reason: string, absorbed: list<RecurringSeries>}
     */
    public function planCandidate(array $candidate, Collection $existing): array
    {
        $stableKey = (string) $candidate['stable_key'];
        $sameProvider = $existing->filter(fn (RecurringSeries $series): bool => $this->sameProvider(
            $series,
            $candidate,
            $stableKey,
        ));

        // A series the user deleted is a decision to leave alone. One this
        // process absorbed is its own bookkeeping, and must not read as one.
        $deleted = $sameProvider->filter(fn (RecurringSeries $series): bool => $series->trashed()
            && $series->getAttribute('merged_into_id') === null);

        if ($deleted->isNotEmpty()) {
            return $this->resolution('review_required', null, 'deleted_identity');
        }

        $active = $sameProvider->reject(fn (RecurringSeries $series): bool => $series->trashed());
        $sameAccount = $active->filter(fn (RecurringSeries $series): bool => $series->getAttribute('account_id') === ($candidate['account_id'] ?? null));

        if ($sameAccount->isNotEmpty()) {
            return $this->resolveSameAccount($sameAccount, $candidate, $stableKey);
        }

        return $this->resolveAccountChange($active, $candidate);
    }

    /**
     * One contract, on the account it is billed to.
     *
     * More than one stored series can answer to a single identity once the key
     * stops varying with a processor reference the bank rewrites each month:
     * what were several rows are one obligation, and were only ever separate
     * because the reference made them look separate. Sending that to review
     * would freeze them there, since every later run reaches the same verdict
     * and nothing writes. They are collapsed instead, into the oldest, which is
     * the one carrying the history.
     *
     * @param  Collection<int, RecurringSeries>  $sameAccount
     * @param  array<string, mixed>  $candidate
     * @return array{action: string, series: RecurringSeries|null, reason: string, absorbed: list<RecurringSeries>}
     */
    private function resolveSameAccount(Collection $sameAccount, array $candidate, string $stableKey): array
    {
        // One key, not a list of them: Collection::sortBy treats a list of
        // callables as comparators rather than as value extractors, which
        // silently orders by whatever the first one happens to return.
        $ordered = $sameAccount
            ->sortBy(fn (RecurringSeries $series): string => sprintf(
                '%s|%s',
                CarbonImmutable::parse($series->getAttribute('first_occurred_on'))->toDateString(),
                (string) $series->getAttribute('id'),
            ))
            ->values();

        /** @var RecurringSeries $survivor */
        $survivor = $ordered->first();
        $absorbed = $ordered->slice(1)->values()->all();

        if ($absorbed !== []) {
            return $this->resolution('merged', $survivor, 'absorbed_duplicate_identity', $absorbed);
        }

        return $this->resolution(
            $this->isMergedObservation($survivor, $candidate, $stableKey)
                ? 'merged'
                : ($this->isUnchanged($survivor, $candidate) ? 'unchanged' : 'updated'),
            $survivor,
            'stable_identity',
        );
    }

    /**
     * The same contract billed to a different account, or a new one.
     *
     * @param  Collection<int, RecurringSeries>  $active
     * @param  array<string, mixed>  $candidate
     * @return array{action: string, series: RecurringSeries|null, reason: string, absorbed: list<RecurringSeries>}
     */
    private function resolveAccountChange(Collection $active, array $candidate): array
    {
        if ($active->count() === 1) {
            /** @var RecurringSeries $series */
            $series = $active->first();

            if ($this->hasContinuity($series, $candidate)) {
                return $this->resolution('merged', $series, 'account_change_with_continuity');
            }
        }

        if ($active->count() > 1) {
            return $this->resolution('review_required', null, 'ambiguous_account_change');
        }

        return $this->resolution('created', null, 'new_identity');
    }

    /**
     * @param  list<RecurringSeries>  $absorbed
     * @return array{action: string, series: RecurringSeries|null, reason: string, absorbed: list<RecurringSeries>}
     */
    private function resolution(string $action, ?RecurringSeries $series, string $reason, array $absorbed = []): array
    {
        return [
            'action' => $action,
            'series' => $series,
            'reason' => $reason,
            'absorbed' => $absorbed,
        ];
    }

    /**
     * Stable merchant identity stored independently from account and direction.
     * Those dimensions remain explicit matching constraints.
     *
     * @api
     */
    public function identityKey(array $candidate): string
    {
        return (string) $candidate['stable_key'];
    }

    /**
     * Whether a stored series carries this identity — as its own key, as an
     * alias seen on an earlier charge, or as the legacy merchant key read under
     * today's rules.
     *
     * Recognition is by containment, not equality, because a bank shortens and
     * lengthens the same name at will: "DIGI" and "DIGI SPAIN TELECOM" are one
     * direct debit. Grouping stays exact — only recognition is generous — so
     * two real contracts at one provider still key apart, and a candidate that
     * recognises more than one series is sent to review rather than guessed at.
     */
    public function matchesIdentity(RecurringSeries $series, string $stableKey): bool
    {
        if ($stableKey === '') {
            return false;
        }

        $stored = [
            (string) $series->getAttribute('identity_key'),
            ...array_map('strval', $series->identityAliases()),
            (string) $series->getAttribute('merchant_key'),
        ];

        foreach ($stored as $key) {
            if ($key !== '' && ($key === $stableKey || $this->merchantKeys->shareIdentity($key, $stableKey))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function sameProvider(RecurringSeries $series, array $candidate, string $stableKey): bool
    {
        if ($series->getAttribute('direction') !== $candidate['direction']
            || $series->getAttribute('currency_code') !== $candidate['currency_code']) {
            return false;
        }

        return $this->matchesIdentity($series, $stableKey);
    }

    /**
     * A cross-account merge is allowed only when observations meet at the
     * existing cadence boundary. This rejects a same-provider coincidence.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function hasContinuity(RecurringSeries $series, array $candidate): bool
    {
        $last = $series->getAttribute('last_occurred_on');
        $first = $candidate['first_occurred_on'] ?? null;

        if ($last === null || $first === null) {
            return false;
        }

        $gap = abs(CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::parse($first)));
        $interval = max(
            1,
            (int) ($series->getAttribute('interval_days') ?? 0),
            (int) ($candidate['interval_days'] ?? 0),
        );

        return $gap <= max(45, $interval * 2);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function isMergedObservation(RecurringSeries $series, array $candidate, string $stableKey): bool
    {
        if ($series->getAttribute('identity_key') !== $stableKey) {
            return true;
        }

        if ($series->getAttribute('match_field') !== $candidate['match_field']) {
            return true;
        }

        return array_diff($candidate['identity_aliases'] ?? [], $series->identityAliases()) !== [];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function isUnchanged(RecurringSeries $series, array $candidate): bool
    {
        $fields = [
            'cadence',
            'interval_days',
            'expected_amount',
            'amount_is_variable',
            'account_id',
            'first_occurred_on',
            'last_occurred_on',
            'next_expected_on',
            'occurrence_count',
            'category_id',
        ];

        foreach ($fields as $field) {
            if ($this->normaliseValue($series->getAttribute($field)) !== $this->normaliseValue($candidate[$field] ?? null)) {
                return false;
            }
        }

        if (! $series->relationLoaded('transactions')) {
            return false;
        }

        $storedTransactionIds = $series->transactions->pluck('id')->sort()->values()->all();
        $candidateTransactionIds = collect($candidate['transaction_ids'] ?? [])->sort()->values()->all();

        return $storedTransactionIds === $candidateTransactionIds;
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
