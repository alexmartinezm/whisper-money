import { expandCategorySelection } from '@/lib/transaction-category-filter';
import { type Category } from '@/types/category';
import { type Transaction, type TransactionFilters } from '@/types/transaction';
import { formatDate, toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { type SortingState } from '@tanstack/react-table';
import { isToday, isValid, isYesterday } from 'date-fns';

/**
 * The table groups rows under a header per day only while it is sorted by
 * date: sorted by anything else, the rows of one day are scattered and the
 * headers would repeat.
 */
export function isSortedByDate(sorting: SortingState): boolean {
    return sorting[0]?.id === 'transaction_date';
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * What a day header says about its day: "Today" and the full date beside it,
 * or the weekday and the date without it. The year only shows outside the
 * current one.
 */
export function formatDayHeading(
    date: string,
    locale: string,
): { name: string; date: string } {
    const day = toLocalDate(date);

    if (!isValid(day)) {
        return { name: date, date: '' };
    }

    const year = day.getFullYear() === new Date().getFullYear() ? '' : ' yyyy';
    const relativeName = isToday(day)
        ? __('Today')
        : isYesterday(day)
          ? __('Yesterday')
          : null;

    if (relativeName) {
        return {
            name: relativeName,
            date: formatDate(day, `EEEE, d MMMM${year}`, locale),
        };
    }

    return {
        name: capitalize(formatDate(day, 'EEEE', locale)),
        date: formatDate(day, `d MMMM${year}`, locale),
    };
}

/**
 * The short line above a day of the compact list: "Today · 26 Oct", or the
 * weekday and the date. The year only shows outside the current one.
 */
export function formatDayBand(date: string, locale: string): string {
    const day = toLocalDate(date);

    if (!isValid(day)) {
        return date;
    }

    const year = day.getFullYear() === new Date().getFullYear() ? '' : ' yyyy';
    const name = isToday(day)
        ? __('Today')
        : isYesterday(day)
          ? __('Yesterday')
          : formatDate(day, 'EEEE', locale);

    return `${name} · ${formatDate(day, `d MMM${year}`, locale)}`;
}

export function formatTransactionCount(count: number): string {
    return count === 1
        ? __('1 transaction')
        : __(':count transactions', { count });
}

/** An amount in some currency on some day: a row, or a day's sum of rows. */
export type DatedAmount = Pick<
    Transaction,
    'amount' | 'currency_code' | 'transaction_date'
>;

/**
 * An amount in the user's currency: a number once known, null when its day
 * holds no rate for its currency, undefined while it is asked for.
 */
export type AmountInUserCurrency = (
    amount: DatedAmount,
) => number | null | undefined;

/**
 * What a row adds to its day's total under the list's filters, in its own
 * currency: its amount, or null when only the server can tell.
 */
export type RowTotalAmount = (transaction: Transaction) => number | null;

const wholeAmount: RowTotalAmount = (transaction) => transaction.amount;

/**
 * What a row adds to its day's total, the way `TransactionDayTotals` adds it on
 * the server. A split transaction stands at its own amount unless the filters
 * narrow its lines: a label it carries keeps them all, labels living on the
 * transaction, and otherwise it adds only the lines in a wanted category.
 *
 * A cashflow block is decided by the budgets the page does not hold, so under
 * one a split transaction has no amount here.
 */
export function createRowTotalAmount(
    filters: Pick<
        TransactionFilters,
        'categoryIds' | 'labelIds' | 'cashflowBlock'
    >,
    categories: Category[],
): RowTotalAmount {
    const { categoryIds, labelIds, cashflowBlock } = filters;

    if (categoryIds.length === 0 && labelIds.length === 0 && !cashflowBlock) {
        return wholeAmount;
    }

    const wantedCategoryIds = expandCategorySelection(categoryIds, categories);

    return (transaction) => {
        if (!transaction.splits?.length) {
            return transaction.amount;
        }

        if (cashflowBlock) {
            return null;
        }

        if (transaction.label_ids?.some((id) => labelIds.includes(id))) {
            return transaction.amount;
        }

        return transaction.splits
            .filter((line) => wantedCategoryIds.has(line.category_id))
            .reduce((sum, line) => sum + line.amount, 0);
    };
}

/**
 * A day's rows added up per currency, the way the server totals a day before
 * converting: each currency converts once, so its rows' rounding never adds
 * up to a cent the server's figure does not have.
 */
export function sumPerCurrency(
    transactions: Transaction[],
    rowTotalAmount: RowTotalAmount = wholeAmount,
): DatedAmount[] {
    const sums = new Map<string, DatedAmount>();

    for (const transaction of transactions) {
        const currency = transaction.currency_code.toUpperCase();
        const sum = sums.get(currency);
        const amount = rowTotalAmount(transaction) ?? transaction.amount;

        if (sum) {
            sum.amount += amount;
        } else {
            sums.set(currency, {
                amount,
                currency_code: currency,
                transaction_date: transaction.transaction_date,
            });
        }
    }

    return [...sums.values()];
}

export type DayTotalState =
    | { status: 'ready'; amount: number }
    | { status: 'pending' }
    | { status: 'missing' };

/**
 * A day's amounts added up in the user's currency. One amount without a
 * rate leaves the whole total out: a sum that silently skipped it, or added it
 * unconverted, would be a wrong figure printed as a right one.
 */
export function sumDayTotal(
    amounts: DatedAmount[],
    amountInUserCurrency: AmountInUserCurrency,
): DayTotalState {
    let amount = 0;
    let isPending = false;

    for (const dated of amounts) {
        const converted = amountInUserCurrency(dated);

        if (converted === null) {
            return { status: 'missing' };
        }

        if (converted === undefined) {
            isPending = true;
            continue;
        }

        amount += converted;
    }

    return isPending ? { status: 'pending' } : { status: 'ready', amount };
}

/** What a day header says about its day: how many rows and their total. */
export interface DaySummary {
    count: number;
    total: DayTotalState;
}

/** A day as the server counted it, its total null when a rate was missing. */
export interface ServerDayTotal {
    count: number;
    total: number | null;
}

/** The summary of each day, looked up by its `Y-m-d` date. */
export type DaySummaryOf = (date: string) => DaySummary;

/**
 * Every transaction of a day, all of them in memory, totalled as the server
 * totals a day: per currency first, then each sum converted once.
 */
export function summarizeDay(
    transactions: Transaction[],
    amountInUserCurrency: AmountInUserCurrency,
    rowTotalAmount: RowTotalAmount = wholeAmount,
): DaySummary {
    return {
        count: transactions.length,
        total: sumDayTotal(
            sumPerCurrency(transactions, rowTotalAmount),
            amountInUserCurrency,
        ),
    };
}

/** A row and what it adds to its day: a change to either moves the total. */
function rowFingerprint(
    transaction: Transaction,
    rowTotalAmount: RowTotalAmount,
): string {
    const lines = transaction.splits
        ?.map((line) => `${line.category_id}:${line.amount}`)
        .join();

    return `${transaction.id}|${transaction.currency_code}|${transaction.amount}|${rowTotalAmount(transaction) ?? lines}`;
}

/**
 * The server's figure for a day, moved by whatever the page changed since it
 * served the day's rows: a deleted row, an edited amount, a row moved in from
 * another day. Rows the page has not touched cancel out, so only the changed
 * ones need a rate; one in another currency leaves the total out, as a missing
 * rate does, and so does a changed row the page cannot value (see
 * `createRowTotalAmount`).
 *
 * @param served The day's rows as the server handed them over.
 * @param loaded The day's rows as the page holds them now.
 */
export function reconcileDaySummary(
    server: ServerDayTotal | undefined,
    served: Transaction[],
    loaded: Transaction[],
    userCurrency: string,
    rowTotalAmount: RowTotalAmount = wholeAmount,
): DaySummary {
    // Served without a figure: the list is switching to a date sort and the
    // answer that carries one is still on its way.
    if (server === undefined && served.length > 0) {
        return { count: loaded.length, total: { status: 'pending' } };
    }

    const count = (server?.count ?? 0) - served.length + loaded.length;
    const fingerprint = (row: Transaction) =>
        rowFingerprint(row, rowTotalAmount);
    const servedKeys = new Set(served.map(fingerprint));
    const loadedKeys = new Set(loaded.map(fingerprint));
    const removed = served.filter((row) => !loadedKeys.has(fingerprint(row)));
    const added = loaded.filter((row) => !servedKeys.has(fingerprint(row)));

    const canMoveTotal = (row: Transaction) =>
        row.currency_code.toUpperCase() === userCurrency.toUpperCase() &&
        rowTotalAmount(row) !== null;

    if (server?.total === null || ![...removed, ...added].every(canMoveTotal)) {
        return { count, total: { status: 'missing' } };
    }

    const addUp = (rows: Transaction[]) =>
        rows.reduce((sum, row) => sum + (rowTotalAmount(row) ?? 0), 0);
    const amount = (server?.total ?? 0) + addUp(added) - addUp(removed);

    return { count, total: { status: 'ready', amount } };
}
