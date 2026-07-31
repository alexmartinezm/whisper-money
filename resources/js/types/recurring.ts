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

export interface RecurringSummary {
    monthly_expense: number;
    monthly_income: number;
    yearly_expense: number;
    active_count: number;
}

export interface UpcomingRecurringCharge {
    id: UUID;
    display_name: string;
    expected_amount: number;
    amount_is_variable: boolean;
    currency_code: string;
    next_expected_on: string;
    cadence: RecurringCadence;
    category: RecurringSeriesCategory | null;
}
