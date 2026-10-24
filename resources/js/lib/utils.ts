import { InertiaLinkProps } from '@inertiajs/react';
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function isSameUrl(
    url1: NonNullable<InertiaLinkProps['href']>,
    url2: NonNullable<InertiaLinkProps['href']>,
) {
    return resolveUrl(url1) === resolveUrl(url2);
}

export function resolveUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

export function appendNoteIfNotPresent(
    existingNotes: string | undefined,
    newNote: string,
): string {
    if (!existingNotes) {
        return newNote;
    }

    if (existingNotes.includes(newNote)) {
        return existingNotes;
    }

    return `${existingNotes}\n${newNote}`;
}

/**
 * `text` cut to at most `max` UTF-16 units, never through the middle of a
 * character written as two of them (an emoji): the lone half left behind
 * makes the JSON invalid, and the server turns down the whole request.
 */
export function truncateText(text: string, max: number): string {
    if (text.length <= max) {
        return text;
    }

    const cut = text.slice(0, max);
    const last = cut.charCodeAt(cut.length - 1);

    return last >= 0xd800 && last <= 0xdbff ? cut.slice(0, -1) : cut;
}
