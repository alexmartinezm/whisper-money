import { describe, expect, it } from 'vitest';
import { truncateText } from './utils';

/** Whether `text` holds no half of a surrogate pair on its own. */
function isWellFormed(text: string): boolean {
    try {
        encodeURIComponent(text);

        return true;
    } catch {
        return false;
    }
}

describe('truncateText', () => {
    it('leaves a text that fits as it is', () => {
        expect(truncateText('Mercadona', 9)).toBe('Mercadona');
        expect(truncateText('Café 😀', 20)).toBe('Café 😀');
    });

    it('cuts a long text to the limit', () => {
        expect(truncateText('Mercadona Valencia', 9)).toBe('Mercadona');
    });

    it('drops an emoji the limit would split in two', () => {
        // "😀" is two UTF-16 units: the limit falls between them.
        const cut = truncateText('abc😀def', 4);

        expect(cut).toBe('abc');
        expect(isWellFormed(cut)).toBe(true);
    });

    it('keeps an emoji that ends right at the limit', () => {
        expect(truncateText('abc😀def', 5)).toBe('abc😀');
    });
});
