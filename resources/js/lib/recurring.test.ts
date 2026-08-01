import { daysUntil, monthlyEquivalent } from '@/lib/recurring';
import { describe, expect, it } from 'vitest';

describe('monthlyEquivalent', () => {
    it('leaves a monthly amount untouched', () => {
        expect(monthlyEquivalent(-1299, 'monthly')).toBe(-1299);
    });

    it('scales a weekly amount up to a month', () => {
        expect(monthlyEquivalent(-1000, 'weekly')).toBe(-4333);
    });

    it('scales a biweekly amount up to a month', () => {
        expect(monthlyEquivalent(-1000, 'biweekly')).toBe(-2167);
    });

    it('scales a quarterly amount down to a month', () => {
        expect(monthlyEquivalent(-3000, 'quarterly')).toBe(-1000);
    });

    it('scales a yearly amount down to a month', () => {
        expect(monthlyEquivalent(-12000, 'yearly')).toBe(-1000);
    });

    it('keeps income positive', () => {
        expect(monthlyEquivalent(240000, 'monthly')).toBe(240000);
    });
});

describe('daysUntil', () => {
    const today = new Date(2026, 6, 31);

    it('returns zero for today', () => {
        expect(daysUntil('2026-07-31T00:00:00.000000Z', today)).toBe(0);
    });

    it('counts forward', () => {
        expect(daysUntil('2026-08-05T00:00:00.000000Z', today)).toBe(5);
    });

    it('goes negative once the date has passed', () => {
        expect(daysUntil('2026-07-20T00:00:00.000000Z', today)).toBe(-11);
    });

    it('ignores the time of day on the target', () => {
        expect(daysUntil('2026-08-01T23:45:00.000000Z', today)).toBe(1);
    });
});
