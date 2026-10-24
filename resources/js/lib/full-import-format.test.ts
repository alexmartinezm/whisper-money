import { aiOutcomeNote } from '@/lib/full-import-format';
import { type ImportAiStatus, type ImportStats } from '@/types/full-import';
import { describe, expect, it } from 'vitest';

/** 7 blank rows the AI was given, and 3 split in Money Pro it never touches. */
function stats(status: ImportAiStatus, applied = 0): ImportStats {
    return {
        uncategorized: 10,
        ai: { status, total: 7, processed: applied, applied },
    };
}

describe('aiOutcomeNote', () => {
    it('counts only the rows the AI was given while it works', () => {
        expect(aiOutcomeNote(stats('queued'), 'en')).toBe(
            'The AI is categorizing the 7 transactions that arrived without a category. It takes a few minutes.',
        );
        expect(aiOutcomeNote(stats('running', 2), 'en')).toBe(
            'The AI is categorizing the 7 transactions that arrived without a category. It takes a few minutes.',
        );
    });

    it('counts only the rows the AI left when it could not finish', () => {
        expect(aiOutcomeNote(stats('failed'), 'en')).toBe(
            "The AI couldn't finish: 7 transactions stayed uncategorized.",
        );
        expect(aiOutcomeNote(stats('failed', 6), 'en')).toBe(
            "The AI couldn't finish: 1 transaction stayed uncategorized.",
        );
    });

    it('counts only the rows a paid plan would categorize', () => {
        expect(aiOutcomeNote(stats('unavailable'), 'en')).toBe(
            '7 transactions stayed uncategorized. With a paid plan, the AI categorizes them for you.',
        );
    });

    it('counts every blank row when it only says they arrived without one', () => {
        expect(aiOutcomeNote(stats('onboarding'), 'en')).toBe(
            '10 transactions arrived without a category. You can categorize them in a moment.',
        );
    });

    it('falls back on the blank rows when the AI total is unknown', () => {
        expect(
            aiOutcomeNote(
                { uncategorized: 4, ai: { status: 'unavailable' } },
                'en',
            ),
        ).toBe(
            '4 transactions stayed uncategorized. With a paid plan, the AI categorizes them for you.',
        );
    });

    it('says nothing when the AI was skipped', () => {
        expect(aiOutcomeNote(stats('skipped'), 'en')).toBeNull();
    });
});
