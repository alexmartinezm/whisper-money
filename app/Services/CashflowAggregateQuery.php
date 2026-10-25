<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Adds a window of transactions up in SQL for the cashflow figures, instead of
 * hydrating every row and summing in PHP.
 *
 * Unsplit rows are grouped by everything the cashflow rules read: account,
 * category, currency, for uncategorized rows the sign and, when the caller
 * asks for them, the tracked labels the row carries. The totals must still
 * match the row-by-row path to the cent, and two steps round per row: the
 * currency conversion and the owner's share of a partly owned account. A row
 * that needs either keeps its own date and amount in the group key, so
 * identical rows collapse into one group whose single conversion is multiplied
 * by the count. Every other row is in the user's currency at full ownership,
 * where the sum is exact.
 *
 * Split transactions are the exception: they are still hydrated and expanded
 * into their postings by {@see ConvertsTransactionCurrency::effectiveTransactions()},
 * one per split line, and each posting is a group of its own. A line is
 * converted on its own and the last one takes whatever the converted parent
 * leaves, a residue SQL could only reproduce by repeating that walk; the owner's
 * share is then rounded per posting. Reusing the expansion keeps those figures
 * exact by construction, and split transactions are few next to the rest.
 */
class CashflowAggregateQuery
{
    use ConvertsTransactionCurrency;

    /**
     * Where a row sits in (transaction_date, id) order, as bytes: a group sorts
     * by its earliest row, a split posting by its transaction. Both sides of
     * the merge read the same expression, and PHP compares it with strcmp(),
     * so MySQL's collation never orders two keys differently.
     */
    private const FIRST_SEEN_SQL = "cast(concat(transactions.transaction_date, '|', transactions.id) as binary)";

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * The groups of $transactions, ordered by where each group's first row sits
     * in (transaction_date, id) order, which is the order the row-by-row path
     * meets them in: callers that group further keep their first-seen order.
     * The postings of a split transaction come in its place, in line order.
     *
     * With $trackedLabelIds, a group also holds only rows carrying the same
     * of those labels (trashed labels left out, as the `labels` relation leaves
     * them out), and its stand-in carries them as its `labels` relation, so
     * {@see CashflowBlockQuery::blockOf()} reads a group as it reads a row.
     * A split posting carries its transaction's labels, as it always has.
     *
     * @param  Builder<Transaction>  $transactions  the window, before
     *                                              {@see Transaction::scopeCountingTowardsTotals()}
     * @param  list<string>  $trackedLabelIds
     * @return Collection<int, CashflowAggregate>
     */
    public function aggregate(Builder $transactions, string $userCurrency, array $trackedLabelIds = []): Collection
    {
        return $this->aggregates($transactions, $userCurrency, $trackedLabelIds);
    }

    /**
     * The same groups, also split by calendar month.
     *
     * @param  Builder<Transaction>  $transactions
     * @return Collection<string, Collection<int, CashflowAggregate>> keyed by `Y-m`
     */
    public function aggregateByMonth(Builder $transactions, string $userCurrency): Collection
    {
        return $this->aggregates($transactions, $userCurrency, [], "date_format(transactions.transaction_date, '%Y-%m')")
            ->groupBy(fn (CashflowAggregate $aggregate): string => (string) $aggregate->bucket);
    }

    /**
     * The same groups, also split by named periods that do not overlap. A row
     * in none of them is left out.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  array<string, PeriodComparator>  $periods
     * @param  list<string>  $trackedLabelIds  as {@see self::aggregate()} reads them
     * @return Collection<string, Collection<int, CashflowAggregate>> keyed by period name, every name present
     */
    public function aggregateByPeriod(Builder $transactions, string $userCurrency, array $periods, array $trackedLabelIds = []): Collection
    {
        $cases = [];
        $bindings = [];

        foreach ($periods as $name => $period) {
            $cases[] = 'when transactions.transaction_date between ? and ? then ?';
            array_push($bindings, $period->from, $period->to, $name);
        }

        $grouped = $this->aggregates($transactions, $userCurrency, $trackedLabelIds, 'case '.implode(' ', $cases).' end', $bindings)
            ->groupBy(fn (CashflowAggregate $aggregate): string => (string) $aggregate->bucket);

        return collect(array_keys($periods))->mapWithKeys(fn (string $name): array => [$name => $grouped->get($name, collect())]);
    }

    /**
     * Both kinds of group, merged in first-seen order. The sort is stable, so
     * the lines of one split transaction, which share its key, keep their
     * order.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  list<string>  $trackedLabelIds
     * @param  array<int, mixed>  $bucketBindings
     * @return Collection<int, CashflowAggregate>
     */
    private function aggregates(Builder $transactions, string $userCurrency, array $trackedLabelIds, ?string $bucket = null, array $bucketBindings = []): Collection
    {
        $bucketSql = $bucket === null ? null : [$bucket, $bucketBindings];

        return collect([
            ...$this->unsplitAggregates((clone $transactions)->whereDoesntHave('splits'), $userCurrency, $trackedLabelIds, $bucketSql),
            ...$this->splitPostingAggregates((clone $transactions)->whereHas('splits'), $userCurrency, $trackedLabelIds !== [], $bucketSql),
        ])
            ->sort(fn (array $left, array $right): int => strcmp($left[0], $right[0]))
            ->map(fn (array $keyed): CashflowAggregate => $keyed[1])
            ->values();
    }

    /**
     * The unsplit rows, added up in SQL, each group with its first-seen key.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  list<string>  $trackedLabelIds
     * @param  array{0: string, 1: array<int, mixed>}|null  $bucket
     * @return list<array{0: string, 1: CashflowAggregate}>
     */
    private function unsplitAggregates(Builder $transactions, string $userCurrency, array $trackedLabelIds, ?array $bucket): array
    {
        $groups = $this->groupedRows($transactions, $userCurrency, $trackedLabelIds, $bucket);

        if ($groups->isEmpty()) {
            return [];
        }

        $accounts = Account::query()->whereIn('id', $groups->pluck('account_id')->unique()->values())->get()->keyBy('id');
        $categoryIds = $groups->pluck('category_id')->filter()->unique()->values();
        $categories = $categoryIds->isEmpty() ? collect() : Category::query()->whereIn('id', $categoryIds)->get()->keyBy('id');
        $labels = $trackedLabelIds === [] ? collect() : Label::query()->whereIn('id', $trackedLabelIds)->get(['id'])->keyBy('id');

        $this->exchangeRateService->preloadRates($userCurrency, $groups
            ->filter(fn (stdClass $group): bool => $group->row_amount !== null
                && strcasecmp($this->sourceCurrency($group->currency_bytes, $accounts->get($group->account_id), $userCurrency), $userCurrency) !== 0)
            ->pluck('row_date')
            ->unique()
            ->values());

        // A model per group is the slow part of this path, so groups that read
        // the same to the cashflow rules share one stand-in.
        $standIns = [];

        return $groups->map(function (stdClass $group) use ($accounts, $categories, $labels, $trackedLabelIds, $userCurrency, &$standIns): array {
            $account = $accounts->get($group->account_id);
            $standIn = $standIns["{$group->account_id}|{$group->category_id}|{$group->amount_sign}|".($group->tracked_labels ?? '')]
                ??= $this->standIn($group, $account, $categories, $trackedLabelIds === [] ? null : $this->carriedLabels($group, $trackedLabelIds, $labels));

            return [$group->first_seen, new CashflowAggregate(
                $standIn,
                $group->row_amount === null
                    ? (int) $group->amount_sum
                    : $this->ownedAmount($account, $group, $userCurrency) * (int) $group->row_count,
                isset($group->bucket) ? (string) $group->bucket : null,
            )];
        })->all();
    }

    /**
     * The transaction a group is classified by. It is never saved. The sign
     * is only kept apart for rows with no category, the one place a predicate
     * reads it; a categorized stand-in's amount is 0.
     *
     * @param  Collection<string, Category>  $categories
     * @param  EloquentCollection<int, Label>|null  $labels  the tracked labels the group carries, null when none are tracked
     */
    private function standIn(stdClass $group, ?Account $account, Collection $categories, ?EloquentCollection $labels): Transaction
    {
        $standIn = (new Transaction)
            ->forceFill([
                'account_id' => $group->account_id,
                'category_id' => $group->category_id,
                'amount' => (int) $group->amount_sign,
            ])
            ->setRelation('account', $account)
            ->setRelation('category', $group->category_id === null ? null : $categories->get($group->category_id));

        if ($labels !== null) {
            $standIn->setRelation('labels', $labels);
        }

        return $standIn;
    }

    /**
     * The tracked labels a group's rows carry, from the positions SQL listed.
     *
     * @param  list<string>  $trackedLabelIds
     * @param  Collection<string, Label>  $labels  the tracked labels that are not trashed, by id
     * @return EloquentCollection<int, Label>
     */
    private function carriedLabels(stdClass $group, array $trackedLabelIds, Collection $labels): EloquentCollection
    {
        $positions = $group->tracked_labels === null ? [] : explode(',', (string) $group->tracked_labels);

        return EloquentCollection::make(array_values(array_filter(array_map(
            fn (string $position): ?Label => $labels->get($trackedLabelIds[(int) $position - 1]),
            $positions,
        ))));
    }

    /**
     * The postings of the split transactions, read the way the row-by-row path
     * always read them: hydrated with what the expansion and the cashflow
     * rules need, and expanded by {@see ConvertsTransactionCurrency::effectiveTransactions()}.
     * Each posting is its own group and its own stand-in, worth what
     * {@see ConvertsTransactionCurrency::convertTransactionAmount()} makes of it.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  array{0: string, 1: array<int, mixed>}|null  $bucket
     * @return list<array{0: string, 1: CashflowAggregate}>
     */
    private function splitPostingAggregates(Builder $transactions, string $userCurrency, bool $withLabels, ?array $bucket): array
    {
        $parents = $transactions
            ->countingTowardsTotals()
            ->selectRaw(self::FIRST_SEEN_SQL.' as cashflow_first_seen')
            ->when($bucket !== null, fn (Builder $query) => $query->selectRaw("{$bucket[0]} as cashflow_bucket", $bucket[1]))
            ->with(['account', 'category', 'splits.category', ...($withLabels ? ['labels:id'] : [])])
            ->orderByRaw(self::FIRST_SEEN_SQL)
            ->get();

        $this->preloadExchangeRates($parents, $userCurrency);

        $postings = [];

        foreach ($parents as $parent) {
            $firstSeen = (string) $parent->getAttribute('cashflow_first_seen');
            $parentBucket = $parent->getAttribute('cashflow_bucket');
            // Read off and dropped, so each posting is the same model it was
            // before the aggregation existed.
            $parent->offsetUnset('cashflow_first_seen');
            $parent->offsetUnset('cashflow_bucket');

            foreach ($this->effectiveTransactions(collect([$parent]), $userCurrency) as $posting) {
                $postings[] = [$firstSeen, new CashflowAggregate(
                    $posting,
                    $this->convertTransactionAmount($posting, $userCurrency),
                    $parentBucket === null ? null : (string) $parentBucket,
                )];
            }
        }

        return $postings;
    }

    /**
     * One row of a group that could not be summed in SQL, the way
     * {@see ConvertsTransactionCurrency::convertTransactionAmount()} reads it.
     */
    private function ownedAmount(?Account $account, stdClass $group, string $userCurrency): int
    {
        $converted = $this->exchangeRateService->convert(
            $this->sourceCurrency($group->currency_bytes, $account, $userCurrency),
            $userCurrency,
            (int) $group->row_amount,
            $group->row_date,
        );

        return $account?->shareOfAmount($converted) ?? $converted;
    }

    /**
     * @param  Builder<Transaction>  $transactions
     * @param  list<string>  $trackedLabelIds
     * @param  array{0: string, 1: array<int, mixed>}|null  $bucket
     * @return Collection<int, stdClass>
     */
    private function groupedRows(Builder $transactions, string $userCurrency, array $trackedLabelIds, ?array $bucket): Collection
    {
        // Mirrors convertTransactionAmount(): a row is exact as a sum only when
        // it is already in the user's currency and its account is fully owned.
        // Compared as binary so MySQL's collation cannot call two codes equal
        // that PHP would not. A trashed account reads as missing in PHP, which
        // can only turn a row here into an exact one, never the other way round.
        $needsOwnRow = 'coalesce(accounts.ownership_percentage, 100) < 100'
            ." or cast(lower(coalesce(nullif(transactions.currency_code, ''), nullif(accounts.currency_code, ''), '')) as binary)"
            .' <> cast(lower(?) as binary)';

        return $transactions
            ->countingTowardsTotals()
            ->select(['transactions.account_id', 'transactions.category_id'])
            // As bytes, so two codes PHP tells apart never share a group.
            ->selectRaw('cast(transactions.currency_code as binary) as currency_bytes')
            ->when($bucket !== null, fn (Builder $query) => $query
                ->selectRaw("{$bucket[0]} as bucket", $bucket[1])
                ->groupBy('bucket'))
            ->when($trackedLabelIds !== [], fn (Builder $query) => $query
                ->selectRaw(self::trackedLabelsSql($trackedLabelIds).' as tracked_labels', [...$trackedLabelIds, ...$trackedLabelIds, ...$trackedLabelIds])
                ->groupBy('tracked_labels'))
            ->selectRaw('case when transactions.category_id is null then sign(transactions.amount) else 0 end as amount_sign')
            ->selectRaw("case when {$needsOwnRow} then transactions.transaction_date end as row_date", [$userCurrency])
            ->selectRaw("case when {$needsOwnRow} then transactions.amount end as row_amount", [$userCurrency])
            ->selectRaw('sum(transactions.amount) as amount_sum')
            ->selectRaw('count(*) as row_count')
            ->selectRaw('min('.self::FIRST_SEEN_SQL.') as first_seen')
            ->groupBy('transactions.account_id', 'transactions.category_id', 'currency_bytes', 'amount_sign', 'row_date', 'row_amount')
            ->orderBy('first_seen')
            ->toBase()
            ->get()
            ->values();
    }

    /**
     * Which of $trackedLabelIds a row carries, as their 1-based positions in
     * that list, ascending and comma-separated (null for none). Positions
     * rather than ids keep the list short enough for `group_concat()`. Only
     * labels that are not trashed count, as the `labels` relation reads them.
     *
     * Its bindings are $trackedLabelIds three times over.
     *
     * @param  list<string>  $trackedLabelIds
     */
    private static function trackedLabelsSql(array $trackedLabelIds): string
    {
        $placeholders = implode(', ', array_fill(0, count($trackedLabelIds), '?'));
        $position = "field(label_transaction.label_id, {$placeholders})";

        return "(select group_concat(distinct {$position} order by {$position} separator ',')"
            .' from label_transaction'
            .' inner join labels on labels.id = label_transaction.label_id and labels.deleted_at is null'
            .' where label_transaction.transaction_id = transactions.id'
            ." and label_transaction.label_id in ({$placeholders}))";
    }
}
