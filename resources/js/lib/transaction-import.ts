import {
    applySuggestion,
    counterpartiesInDescription,
} from '@/lib/ai-import-mapping';
import { isHeaderRow } from '@/lib/column-shape';
import {
    autoDetectColumns,
    detectDateFormat,
    detectExpenseTypes,
    parseFile,
} from '@/lib/file-parser';
import { loadImportConfig } from '@/lib/import-config-storage';
import { type BalanceColumnMapping } from '@/types/balance-import';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type ParsedRow,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';

/**
 * The spreadsheet formats the importers read. The parser handles CSV and
 * Excel; Apple Numbers is not offered because its date cells need a
 * conversion this parser does not do.
 */
export const SUPPORTED_IMPORT_EXTENSIONS = ['.csv', '.xls', '.xlsx'];

export function isSupportedImportFile(file: File | null | undefined): boolean {
    if (!file || !file.name) {
        return false;
    }

    const lastDotIndex = file.name.lastIndexOf('.');

    if (lastDotIndex === -1) {
        return false;
    }

    return SUPPORTED_IMPORT_EXTENSIONS.includes(
        file.name.toLowerCase().slice(lastDotIndex),
    );
}

/** "PDF is not supported", from whatever the file is actually called. */
export function unsupportedFileReason(fileName: string): string {
    const dot = fileName.lastIndexOf('.');

    return dot === -1
        ? __('That file has no format we recognise')
        : __(':format is not supported', {
              format: fileName.slice(dot + 1).toUpperCase(),
          });
}

/**
 * Parsing happens in the browser, so a file large enough to hang the tab is
 * turned away before it is read rather than after. A decade of movements is
 * comfortably inside this.
 */
export const MAX_IMPORT_FILE_BYTES = 10 * 1024 * 1024;

/**
 * What a file becomes once it has been read and guessed at: the rows, and the
 * mapping the user is asked to confirm. The reading happens in the browser —
 * the file itself is never uploaded.
 */
export interface ParsedImportFile {
    file: File;
    rows: ParsedRow[];
    /** Each row's own number in the file, so a problem can be found in a spreadsheet. */
    rowNumbers: number[];
    /**
     * The file each row came from, aligned with `rows`, when several files
     * were read as one: a row number alone would not say which to open.
     */
    rowFiles?: string[];
    headers: string[];
    /**
     * The row the headers were read from really is one. False for a file that
     * starts with its first movement: its "headers" are that row's cells, and
     * none of them may leave the browser (see describeColumns).
     */
    hasHeaderRow: boolean;
    columnOptions: ColumnOption[];
    mapping: ColumnMapping;
    dateFormat: DateFormat;
    /** False while the format still needs confirming, either way it was reached. */
    dateFormatDetected: boolean;
    /**
     * The dates could be read more than one way. Kept apart from
     * `dateFormatDetected` because a stored layout settles an undetected format
     * but must not settle an ambiguous one: a wrong format saved last time is
     * exactly what the user needs the chance to correct.
     */
    dateFormatAmbiguous: boolean;
    /** The mapping came from a stored layout saved as the AI's suggestion, unchanged. */
    aiSuggested?: boolean;
}

/** How many example cells a column offers as evidence of what it holds. */
const COLUMN_EXAMPLES = 3;

/**
 * Three values from the middle of the column rather than the top: the first
 * rows under a header are often blank or a carried-over balance, and neither
 * tells the user what the column actually holds.
 */
function buildColumnOptions(
    headers: string[],
    columns: unknown[][],
    headerRowIndex: number,
): ColumnOption[] {
    return headers.map((header, index) => {
        const columnData = columns[index] || [];
        const middleIndex = Math.floor(columnData.length / 2);
        const from = Math.max(headerRowIndex + 1, middleIndex);

        return {
            value: header,
            label: header,
            examples: columnData
                .slice(from, from + COLUMN_EXAMPLES)
                .filter(
                    (cell) =>
                        cell !== null &&
                        cell !== undefined &&
                        String(cell).trim() !== '',
                )
                .map((cell) => String(cell))
                .slice(0, COLUMN_EXAMPLES),
        };
    });
}

/**
 * Read a file and guess what its columns are. Throws whatever the parser threw
 * when the file is not something we can read at all.
 */
export async function parseImportFile(
    file: File,
    locale?: string,
): Promise<ParsedImportFile> {
    const { headers, data, rowNumbers, columns, headerRowIndex } =
        await parseFile(file);
    const mapping = autoDetectColumns(headers);
    mapping.expense_types = detectExpenseTypes(data, mapping.transaction_type);

    let dateFormat = DateFormat.YearMonthDay;
    let dateFormatDetected = false;
    let dateFormatAmbiguous = false;

    if (mapping.transaction_date) {
        const detected = detectDateFormat(
            data,
            mapping.transaction_date,
            locale,
        );

        if (detected) {
            dateFormat = detected.format;
            dateFormatAmbiguous = detected.ambiguous;
            dateFormatDetected = !detected.ambiguous;
        }
    }

    return {
        file,
        rows: data,
        rowNumbers,
        headers,
        hasHeaderRow: isHeaderRow(columns, headerRowIndex),
        columnOptions: buildColumnOptions(headers, columns, headerRowIndex),
        mapping,
        dateFormat,
        dateFormatDetected,
        dateFormatAmbiguous,
    };
}

/** Every mapped column is one the file actually has. */
export function mapsOnlyKnownColumns(
    mapping: ColumnMapping | BalanceColumnMapping,
    headers: string[],
): boolean {
    // The expense types are values of a column, not columns; and a field set
    // back to "None" is saved empty, which names no column at all.
    return Object.entries(mapping)
        .filter(([field]) => field !== 'expense_types')
        .map(([, column]) => column)
        .filter((column) => column !== null && column !== '')
        .every((column) =>
            Array.isArray(column)
                ? column.every((one) => headers.includes(one as string))
                : headers.includes(column as string),
        );
}

/**
 * Overlay the layout this account was last imported with, so the second file
 * from the same bank is not mapped by hand again. A stored layout naming
 * columns this file does not have belongs to a different export and is ignored.
 *
 * Null when there is nothing worth applying, so a caller whose user has been
 * editing the mapping meanwhile can leave their edits alone.
 */
export async function applyStoredImportConfig(
    parsed: ParsedImportFile,
    accountId: UUID,
): Promise<ParsedImportFile | null> {
    const stored = await loadImportConfig(accountId);

    if (
        !stored ||
        !mapsOnlyKnownColumns(stored.columnMapping, parsed.headers)
    ) {
        return null;
    }

    return {
        ...parsed,
        mapping: { ...parsed.mapping, ...stored.columnMapping },
        dateFormat: stored.dateFormat,
        dateFormatDetected: !parsed.dateFormatAmbiguous,
        aiSuggested: stored.aiSuggested === true,
    };
}

/** A transaction mapping on screen, with the date format it reads with. */
export interface TransactionMappingDraft {
    mapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
}

/**
 * Lay the AI's suggestion over the mapping, leaving the fields in `keep` as
 * the user picked them. A new date column reads its own format, and a new
 * type column brings its own expense values to tick. A suggested creditor or
 * debtor that lands on the description the mapping ends up with (one picked
 * by hand, say) keeps the column it had.
 */
export function applyTransactionSuggestion(
    draft: TransactionMappingDraft,
    rows: ParsedRow[],
    suggested: Partial<ColumnMapping>,
    keep: ReadonlySet<string>,
    locale?: string,
): TransactionMappingDraft {
    const { mapping, format } = applySuggestion(
        draft.mapping,
        suggested,
        keep,
        'transaction_date',
        rows,
        locale,
    );

    for (const field of counterpartiesInDescription(
        mapping,
        mapping.description,
    )) {
        if (!keep.has(field)) {
            mapping[field] = draft.mapping[field];
        }
    }

    const expenseTypes =
        mapping.transaction_type !== draft.mapping.transaction_type
            ? detectExpenseTypes(rows, mapping.transaction_type)
            : mapping.expense_types;

    return {
        ...draft,
        ...format,
        mapping: { ...mapping, expense_types: expenseTypes },
    };
}
