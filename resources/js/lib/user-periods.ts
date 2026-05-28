import {
    addMonths,
    addQuarters,
    addYears,
    endOfDay,
    startOfDay,
    subDays,
} from 'date-fns';

export type UserMonthStartDay = 1 | 25 | 26 | 27 | 28;
export type UserPeriodType = 'month' | 'quarter' | 'year';

export const USER_MONTH_START_DAYS: UserMonthStartDay[] = [1, 25, 26, 27, 28];

export function userMonthStartDay(value: unknown): UserMonthStartDay {
    return USER_MONTH_START_DAYS.includes(value as UserMonthStartDay)
        ? (value as UserMonthStartDay)
        : 1;
}

export function getUserPeriodRange(
    date: Date,
    periodType: UserPeriodType,
    monthStartDay: UserMonthStartDay,
): { from: Date; to: Date; endInclusive: Date } {
    if (periodType === 'quarter') {
        return multiMonthPeriodContaining(date, monthStartDay, 3);
    }

    if (periodType === 'year') {
        return multiMonthPeriodContaining(date, monthStartDay, 12);
    }

    const from = monthStartOnOrBefore(date, monthStartDay);
    const to = addMonths(from, 1);

    return { from, to, endInclusive: endOfDay(subDays(to, 1)) };
}

export function shiftUserPeriod(
    date: Date,
    periodType: UserPeriodType,
    monthStartDay: UserMonthStartDay,
    amount: 1 | -1,
): Date {
    const { from } = getUserPeriodRange(date, periodType, monthStartDay);

    if (periodType === 'quarter') {
        return amount > 0 ? addQuarters(from, 1) : addQuarters(from, -1);
    }

    if (periodType === 'year') {
        return amount > 0 ? addYears(from, 1) : addYears(from, -1);
    }

    return amount > 0 ? addMonths(from, 1) : addMonths(from, -1);
}

export function sameUserPeriod(
    left: Date,
    right: Date,
    periodType: UserPeriodType,
    monthStartDay: UserMonthStartDay,
): boolean {
    return (
        getUserPeriodRange(left, periodType, monthStartDay).from.getTime() ===
        getUserPeriodRange(right, periodType, monthStartDay).from.getTime()
    );
}

function multiMonthPeriodContaining(
    date: Date,
    monthStartDay: UserMonthStartDay,
    months: 3 | 12,
): { from: Date; to: Date; endInclusive: Date } {
    const anchorMonth =
        months === 12 ? 0 : Math.floor(date.getMonth() / months) * months;
    let from = startOfDay(
        new Date(date.getFullYear(), anchorMonth, monthStartDay),
    );

    if (from > date) {
        from = addMonths(from, -months);
    }

    const to = addMonths(from, months);

    return { from, to, endInclusive: endOfDay(subDays(to, 1)) };
}

function monthStartOnOrBefore(
    date: Date,
    monthStartDay: UserMonthStartDay,
): Date {
    let start = startOfDay(
        new Date(date.getFullYear(), date.getMonth(), monthStartDay),
    );

    if (start > date) {
        start = addMonths(start, -1);
    }

    return start;
}
