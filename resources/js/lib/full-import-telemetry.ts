import { valueKind } from '@/lib/column-shape';
import {
    IGNORED_KEY,
    OWN_TRANSFER_KEY,
    type BuiltImport,
} from '@/lib/full-import-plan';
import { isLeavingPage } from '@/lib/leave-page';
import { captureEvent } from '@/lib/posthog';
import { truncateText } from '@/lib/utils';
import {
    type AccountPlanEntry,
    type FullImportMode,
    type FullImportSource,
    type ImportStatus,
} from '@/types/full-import';
import * as Sentry from '@sentry/react';
import { isAxiosError } from 'axios';

/**
 * What the full import tells PostHog and Sentry. Everything here is counts,
 * enums, booleans and, for a file nobody recognised, the names of its
 * columns: never a cell of the file, a file name, or the name of an account,
 * a category or a bank.
 */

/** Where the wizard was opened from. */
export type ImportEntry = 'settings' | 'onboarding';

/** The headers sent for an unrecognised file: enough to spot a changed export. */
const MAX_HEADERS = 40;
const MAX_HEADER_LENGTH = 60;

export function trackImport(
    event: string,
    properties: Record<string, unknown>,
): void {
    captureEvent(event, properties);
}

/**
 * The extensions an event may name. Whatever follows the last dot of a name
 * nobody chose for a format ("Cuentas.Juana") is part of the name.
 */
const KNOWN_EXTENSIONS = new Set([
    'csv',
    'tsv',
    'txt',
    'xls',
    'xlsx',
    'ods',
    'numbers',
    'ofx',
    'qfx',
    'qif',
    'json',
    'xml',
    'pdf',
    'zip',
]);

/**
 * "csv", "xlsx", "none" without a dot, or "other": a known extension alone,
 * never the name before it nor a made-up one.
 */
export function fileExtension(fileName: string): string {
    const dot = fileName.lastIndexOf('.');

    if (dot === -1) {
        return 'none';
    }

    const extension = fileName.slice(dot + 1).toLowerCase();

    return KNOWN_EXTENSIONS.has(extension) ? extension : 'other';
}

/**
 * The headers of an unrecognised file that name a column. A file without a
 * header row has none: the reader took its first movement for them. A
 * header that reads as a value on its own (a date, an amount, an IBAN…) is
 * a cell too, and is left out.
 */
function columnNames(file: {
    headers: string[];
    hasHeaderRow: boolean;
}): string[] {
    if (!file.hasHeaderRow) {
        return [];
    }

    return file.headers
        .filter((header) => valueKind(header) === null)
        .slice(0, MAX_HEADERS)
        .map((header) => truncateText(header, MAX_HEADER_LENGTH));
}

/**
 * What `full_import_file_parsed` says about a file that was read: for an
 * unrecognised one, the names of its columns too, and no `headers` at all
 * when none is left.
 */
export function fileParsedProperties(
    file: { name: string },
    read: { headers: string[]; hasHeaderRow: boolean },
    rows: { count: number; blank: number },
    source: FullImportSource,
    recognized: boolean,
): Record<string, unknown> {
    const headers = recognized ? [] : columnNames(read);

    return {
        recognized,
        source,
        extension: fileExtension(file.name),
        rows: rows.count,
        skipped_blank_rows: rows.blank,
        columns_count: read.headers.length,
        ...(headers.length > 0 ? { headers } : {}),
    };
}

interface SubmittedInput {
    mode: FullImportMode;
    source: FullImportSource;
    built: BuiltImport;
    accountPlan: Record<string, AccountPlanEntry>;
    uncategorized: number;
    estimatedDuplicates: number | null;
    entry: ImportEntry;
}

/** What `full_import_submitted` says about the plan the user confirmed. */
export function submittedProperties({
    mode,
    source,
    built,
    accountPlan,
    uncategorized,
    estimatedDuplicates,
    entry,
}: SubmittedInput): Record<string, unknown> {
    const entries = Object.values(accountPlan);
    const accounts = (action: AccountPlanEntry['action']) =>
        entries.filter((one) => one.action === action).length;
    const newBanks = new Set(
        entries
            .filter((one) => one.action === 'create' && one.bank === null)
            .map((one) => one.newBankName?.trim().toLowerCase())
            .filter(Boolean),
    );
    const categories = built.payload.categories.filter(
        (one) => one.key !== OWN_TRANSFER_KEY && one.key !== IGNORED_KEY,
    );

    return {
        mode,
        source,
        rows: built.transactions.length,
        balances: built.balances.length,
        accounts_new: accounts('create'),
        accounts_mapped: accounts('map'),
        accounts_merged: accounts('merge'),
        accounts_skipped: accounts('skip'),
        banks_new: newBanks.size,
        categories_new: categories.filter((one) => one.action === 'create')
            .length,
        categories_matched: categories.filter((one) => one.action === 'match')
            .length,
        uncategorized,
        estimated_duplicates: estimatedDuplicates,
        entry,
    };
}

/** What `full_import_completed` / `full_import_failed` say about how it went. */
export function outcomeProperties(
    status: ImportStatus,
    seenSince: number,
    now: number,
    entry: ImportEntry,
): Record<string, unknown> {
    const started = status.created_at ? Date.parse(status.created_at) : NaN;
    const finished = status.finished_at ? Date.parse(status.finished_at) : NaN;
    const duration =
        Number.isNaN(started) || Number.isNaN(finished)
            ? now - seenSince
            : finished - started;

    return {
        imported: status.stats.transactions?.imported ?? 0,
        skipped_duplicates: status.stats.transactions?.duplicates ?? 0,
        duration_seconds: Math.max(0, Math.round(duration / 1000)),
        ai_status: status.stats.ai?.status ?? null,
        entry,
    };
}

/** The HTTP status a failed request carries, 0 when it never got an answer. */
export function requestStatus(error: unknown): number {
    return isAxiosError(error) ? (error.response?.status ?? 0) : 0;
}

/**
 * Whether a failure is one somebody should look at. A refusal (4xx) is the
 * server doing its job and the user is told why; a request killed because
 * the page is going away is not a failure at all. A 5xx, a dropped
 * connection while the user stayed, and any error that is not a request are.
 */
export function shouldReportImportError(error: unknown): boolean {
    if (isLeavingPage()) {
        return false;
    }

    if (isAxiosError(error)) {
        const status = error.response?.status;

        return status === undefined || status >= 500;
    }

    return true;
}

export type ImportErrorStage =
    | 'parse'
    | 'plan'
    | 'context'
    | 'create'
    | 'chunks'
    | 'start'
    | 'poll';

/** Send an unexpected failure to Sentry, with nothing of the file in it. */
export function reportImportError(
    error: unknown,
    stage: ImportErrorStage,
    extra: { entry: ImportEntry; source?: FullImportSource; rows?: number },
): void {
    if (!shouldReportImportError(error)) {
        return;
    }

    Sentry.captureException(error, {
        tags: { feature: 'full_import', stage },
        extra,
    });
}
