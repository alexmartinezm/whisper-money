<?php

namespace App\Services\Recurring;

use App\Data\CadenceMatch;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Models\RecurringSeriesTransaction;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\EffectiveTransactionPostings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the charges that repeat in a user's ledger — subscriptions, utility
 * bills, standing payments — and records them as series.
 *
 * Detection is descriptive, never generative: it groups transactions that
 * already exist and never writes to the ledger, because the ledger is owned by
 * bank sync and import, which dedupe on their own fingerprints.
 */
class DetectRecurringSeries
{
    public function __construct(
        private readonly MerchantKeyBuilder $merchantKeys,
        private readonly CadenceClassifier $classifier,
        private readonly EffectiveTransactionPostings $postings,
    ) {}

    /**
     * Rebuild every series for a user, in each space where they hold
     * transactions. Deriving the spaces from the ledger rather than from
     * membership keeps the run proportional to the data that exists.
     *
     * @return int the number of series that survived detection
     */
    public function forUserEverywhere(User $user): int
    {
        // Spaces that already hold series are visited too, not just those with
        // transactions. A space whose ledger was emptied still needs a pass, or
        // its series would stay active forever with nothing behind them.
        $spaceIds = Transaction::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->pluck('space_id')
            ->merge(
                RecurringSeries::query()
                    ->where('user_id', $user->id)
                    ->distinct()
                    ->pluck('space_id'),
            )
            ->filter()
            ->unique();

        if ($spaceIds->isEmpty()) {
            return 0;
        }

        return (int) Space::query()
            ->whereIn('id', $spaceIds)
            ->get()
            ->sum(fn (Space $space): int => $this->forUser($user, $space));
    }

    /**
     * Rebuild every series for one user in one space.
     *
     * @return int the number of series that survived detection
     */
    public function forUser(User $user, ?Space $space = null): int
    {
        $space ??= $user->activeSpace();
        $since = CarbonImmutable::today()->subMonths((int) config('recurring.lookback_months'));

        $descriptions = $this->candidates($user, $space, $since)->pluck('description');
        $documentFrequency = $this->merchantKeys->documentFrequency($descriptions);
        $noiseThreshold = $descriptions->count() * (float) config('recurring.noise_token_fraction');

        $groups = $this->group($user, $space, $since, $documentFrequency, $noiseThreshold);
        $series = $this->classify($groups);

        return $this->persist($user, $space, $series);
    }

    /**
     * The transactions detection is allowed to look at.
     *
     * Client-encrypted descriptions are skipped for the same reason every other
     * server-side text analysis skips them (see
     * `App\Services\Ai\RuleSuggestionAggregator`): the server cannot read them.
     *
     * @return Builder<Transaction>
     */
    private function candidates(User $user, Space $space, CarbonImmutable $since): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->whereNull('description_iv')
            ->whereDate('transaction_date', '>=', $since->toDateString())
            ->whereHas('account');
    }

    /**
     * Bucket transactions by merchant identity. Direction and currency are part
     * of the identity: a refund from a merchant is not an instalment of the
     * subscription, and the same merchant billed in two currencies is two
     * series that must not have their amounts mixed.
     *
     * @param  array<string, int>  $documentFrequency
     * @return array<string, array{match_field: string, merchant_key: string, direction: string, currency_code: string, display_name: string, transactions: list<array{id: string, date: CarbonImmutable, amount: int, account_id: string|null}>}>
     */
    private function group(
        User $user,
        Space $space,
        CarbonImmutable $since,
        array $documentFrequency,
        float $noiseThreshold,
    ): array {
        $groups = [];

        $this->candidates($user, $space, $since)
            ->select(['id', 'description', 'creditor_name', 'debtor_name', 'transaction_date', 'amount', 'currency_code', 'account_id'])
            ->lazyById()
            ->each(function (Transaction $transaction) use (&$groups, $documentFrequency, $noiseThreshold): void {
                if ((int) $transaction->amount === 0) {
                    return;
                }

                $key = $this->merchantKeys->keyFor($transaction, $documentFrequency, $noiseThreshold);

                if ($key === null) {
                    return;
                }

                [$matchField, $merchantKey] = $key;
                $direction = $transaction->amount < 0 ? 'expense' : 'income';
                $currency = (string) $transaction->currency_code;
                $bucket = implode('|', [$matchField, $merchantKey, $direction, $currency]);

                $groups[$bucket] ??= [
                    'match_field' => $matchField,
                    'merchant_key' => $merchantKey,
                    'direction' => $direction,
                    'currency_code' => $currency,
                    'display_name' => $this->merchantKeys->displayNameFor($transaction),
                    'transactions' => [],
                ];

                $groups[$bucket]['transactions'][] = [
                    'id' => $transaction->id,
                    'date' => CarbonImmutable::parse($transaction->transaction_date),
                    'amount' => (int) $transaction->amount,
                    'account_id' => $transaction->account_id,
                ];
            });

        return $groups;
    }

    /**
     * Keep the groups that actually repeat, and describe how.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function classify(array $groups): array
    {
        $minOccurrences = (int) config('recurring.min_occurrences');
        $lapseMultiplier = (float) config('recurring.lapse_interval_multiplier');
        $today = CarbonImmutable::today();
        $series = [];

        foreach ($groups as $group) {
            /** @var list<array{id: string, date: CarbonImmutable, amount: int, account_id: string|null}> $occurrences */
            $occurrences = $group['transactions'];

            if (count($occurrences) < $minOccurrences) {
                continue;
            }

            usort($occurrences, fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            $dates = array_map(fn (array $occurrence): CarbonImmutable => $occurrence['date'], $occurrences);

            $match = $this->classifier->classify($dates);

            if (! $match instanceof CadenceMatch) {
                continue;
            }

            $amounts = array_map(fn (array $occurrence): int => $occurrence['amount'], $occurrences);
            $firstOccurred = $dates[0];
            $lastOccurred = $dates[count($dates) - 1];
            $nextExpected = $lastOccurred->addDays($match->medianGapDays);
            $lapseCutoff = $lastOccurred->addDays((int) round($match->medianGapDays * $lapseMultiplier));

            $series[] = [
                'match_field' => $group['match_field'],
                'merchant_key' => $group['merchant_key'],
                'display_name' => $group['display_name'],
                'direction' => $group['direction'],
                'currency_code' => $group['currency_code'],
                'cadence' => $match->cadence,
                'interval_days' => $match->medianGapDays,
                'expected_amount' => (int) round($this->median($amounts)),
                'amount_is_variable' => $this->isVariable($amounts),
                'account_id' => $this->dominantAccountId($occurrences),
                'first_occurred_on' => $firstOccurred,
                'last_occurred_on' => $lastOccurred,
                'next_expected_on' => $nextExpected,
                'occurrence_count' => count($occurrences),
                'status' => $today->greaterThan($lapseCutoff)
                    ? RecurringSeriesStatus::Lapsed
                    : RecurringSeriesStatus::Active,
                'transaction_ids' => array_map(fn (array $occurrence): string => $occurrence['id'], $occurrences),
            ];
        }

        return $series;
    }

    /**
     * Write the run's result, preserving everything the user decided.
     *
     * The whole assignment is rebuilt inside one transaction: pivot rows are
     * cleared before they are rewritten so a transaction that moved between
     * series cannot collide with the pivot's unique index mid-run.
     *
     * Deleted series are loaded too, and then left alone. Their identity still
     * occupies the unique index, so ignoring them would make the next run
     * collide; resurrecting them would undo a deletion the user asked for.
     *
     * @param  list<array<string, mixed>>  $series
     */
    private function persist(User $user, Space $space, array $series): int
    {
        return DB::transaction(function () use ($user, $space, $series): int {
            $existing = RecurringSeries::withTrashed()
                ->where('user_id', $user->id)
                ->where('space_id', $space->id)
                ->get()
                ->keyBy(fn (RecurringSeries $row): string => $this->identity(
                    $row->match_field,
                    $row->merchant_key,
                    $row->direction,
                    $row->currency_code,
                ));

            RecurringSeriesTransaction::query()
                ->whereIn('recurring_series_id', $existing->reject->trashed()->pluck('id'))
                ->delete();

            $categories = $this->effectiveCategories($series);
            $seen = [];
            $written = 0;

            foreach ($series as $candidate) {
                $identity = $this->identity(
                    $candidate['match_field'],
                    $candidate['merchant_key'],
                    $candidate['direction'],
                    $candidate['currency_code'],
                );
                $seen[] = $identity;

                if ($existing->get($identity)?->trashed() === true) {
                    continue;
                }

                $written++;
                $row = $existing->get($identity) ?? new RecurringSeries([
                    'user_id' => $user->id,
                    'space_id' => $space->id,
                    'match_field' => $candidate['match_field'],
                    'merchant_key' => $candidate['merchant_key'],
                    'direction' => $candidate['direction'],
                    'currency_code' => $candidate['currency_code'],
                    'display_name' => $candidate['display_name'],
                    'user_state' => RecurringSeriesUserState::Detected,
                ]);

                $row->fill([
                    'cadence' => $candidate['cadence'],
                    'interval_days' => $candidate['interval_days'],
                    'expected_amount' => $candidate['expected_amount'],
                    'amount_is_variable' => $candidate['amount_is_variable'],
                    'account_id' => $candidate['account_id'],
                    'category_id' => $categories[$identity] ?? null,
                    'first_occurred_on' => $candidate['first_occurred_on'],
                    'last_occurred_on' => $candidate['last_occurred_on'],
                    'next_expected_on' => $candidate['next_expected_on'],
                    'occurrence_count' => $candidate['occurrence_count'],
                    'status' => $candidate['status'],
                ]);

                $row->save();
                $row->transactions()->attach($candidate['transaction_ids']);
            }

            // A series the run no longer substantiates keeps its history and its
            // user state; it simply stops being current.
            $existing
                ->reject(fn (RecurringSeries $row, string $identity): bool => $row->trashed()
                    || in_array($identity, $seen, true))
                ->each(function (RecurringSeries $row): void {
                    $row->update(['status' => RecurringSeriesStatus::Lapsed]);
                });

            return $written;
        });
    }

    /**
     * The category each series should show, resolved through effective postings
     * so a split charge is attributed to its largest line rather than to the
     * null parent category.
     *
     * @param  list<array<string, mixed>>  $series
     * @return array<string, string|null>
     */
    private function effectiveCategories(array $series): array
    {
        $allIds = collect($series)->flatMap(fn (array $candidate): array => $candidate['transaction_ids'])->unique();

        if ($allIds->isEmpty()) {
            return [];
        }

        /** @var EloquentCollection<int, Transaction> $transactions */
        $transactions = Transaction::query()
            ->whereIn('id', $allIds)
            ->with(['splits'])
            ->get()
            ->keyBy('id');

        $categories = [];

        foreach ($series as $candidate) {
            $tally = [];

            foreach ($candidate['transaction_ids'] as $transactionId) {
                $transaction = $transactions->get($transactionId);

                if (! $transaction instanceof Transaction) {
                    continue;
                }

                $dominant = $this->postings
                    ->forTransaction($transaction)
                    ->sortByDesc(fn ($posting): int => abs($posting->amount))
                    ->first();

                if ($dominant === null || $dominant->categoryId === null) {
                    continue;
                }

                $tally[$dominant->categoryId] = ($tally[$dominant->categoryId] ?? 0) + 1;
            }

            arsort($tally);

            $categories[$this->identity(
                $candidate['match_field'],
                $candidate['merchant_key'],
                $candidate['direction'],
                $candidate['currency_code'],
            )] = array_key_first($tally);
        }

        return $categories;
    }

    private function identity(string $matchField, string $merchantKey, string $direction, string $currencyCode): string
    {
        return implode('|', [$matchField, $merchantKey, $direction, $currencyCode]);
    }

    /**
     * A series bills a variable amount when its spread around the median is a
     * meaningful share of the median — a utility bill rather than a flat fee.
     *
     * @param  list<int>  $amounts
     */
    private function isVariable(array $amounts): bool
    {
        $median = $this->median($amounts);

        if ((int) $median === 0) {
            return false;
        }

        $deviations = array_map(fn (int $amount): float => abs($amount - $median), $amounts);

        return ($this->median($deviations) / abs($median)) > (float) config('recurring.amount_variance_threshold');
    }

    /**
     * @param  list<array{account_id: string|null}>  $occurrences
     */
    private function dominantAccountId(array $occurrences): ?string
    {
        $tally = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['account_id'] === null) {
                continue;
            }

            $tally[$occurrence['account_id']] = ($tally[$occurrence['account_id']] ?? 0) + 1;
        }

        arsort($tally);

        return array_key_first($tally);
    }

    /** @param  list<int|float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
