import { type UUID } from './uuid';

export const RECURRING_CADENCES = [
    'weekly',
    'biweekly',
    'monthly',
    'quarterly',
    'yearly',
] as const;

export type RecurringCadence = (typeof RECURRING_CADENCES)[number];

export type RecurringSeriesStatus = 'active' | 'lapsed';

export type RecurringSeriesUserState = 'detected' | 'confirmed' | 'ignored';

export type RecurringDirection = 'expense' | 'income';

export interface RecurringSeriesCategory {
    id: UUID;
    name: string;
    color: string | null;
    icon: string | null;
}

export interface RecurringSeries {
    id: UUID;
    display_name: string;
    direction: RecurringDirection;
    cadence: RecurringCadence;
    interval_days: number;
    expected_amount: number;
    amount_is_variable: boolean;
    currency_code: string;
    category_id: UUID | null;
    category: RecurringSeriesCategory | null;
    account_id: UUID | null;
    account: { id: UUID; name: string } | null;
    first_occurred_on: string;
    last_occurred_on: string;
    next_expected_on: string;
    occurrence_count: number;
    status: RecurringSeriesStatus;
    user_state: RecurringSeriesUserState;
}

/**
 * Totals for a single currency. The server never adds amounts across
 * currencies, so the screen renders one of these per currency in play.
 */
export interface RecurringCurrencySummary {
    currency_code: string;
    monthly_expense: number;
    monthly_income: number;
    yearly_expense: number;
    active_count: number;
}

/** One expected charge, with the balance it leaves behind. */
export interface ForecastOccurrence {
    date: string;
    series_id: UUID;
    display_name: string;
    amount: number;
    amount_is_variable: boolean;
    balance_after: number;
    category: string | null;
}

/** An irregular charge due past the projection window. */
export interface OutlookCharge {
    date: string;
    series_id: UUID;
    display_name: string;
    amount: number;
    amount_is_variable: boolean;
    category: string | null;
}

export interface OutlookMonth {
    /** YYYY-MM */
    month: string;
    total: number;
    charges: OutlookCharge[];
}

/** The same window walked again with everyday spending in it. */
export interface EverydaySpending {
    /** Median month of non-recurring outflow, negative. */
    monthly: number;
    months_observed: number;
    ending_balance: number;
    lowest: { date: string; balance: number };
}

/** A budget the current pace will overshoot before the period is out. */
export interface BudgetPace {
    id: UUID;
    name: string;
    spent: number;
    available: number;
    projected: number;
    over_by: number;
    period_end: string;
}

export interface CashflowForecast {
    currency_code: string;
    days: number;
    starting_balance: number;
    ending_balance: number;
    expected_in: number;
    expected_out: number;
    lowest: { date: string; balance: number };
    /** The accounts behind `starting_balance`, so the figure can be checked. */
    accounts: { id: UUID; name: string }[];
    /** Spendable accounts held back by the net-worth setting. */
    excluded_accounts: number;
    /** Null when there is too little history to estimate a pace. */
    spending: EverydaySpending | null;
    /** Currencies with series that this projection deliberately leaves out. */
    other_currencies: string[];
    occurrences: ForecastOccurrence[];
    /** Months past the window: no balance out here, only what is due. */
    later: OutlookMonth[];
}
