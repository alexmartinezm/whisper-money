import { isDateText } from '@/lib/file-parser';
import { truncateText } from '@/lib/utils';
import { type ParsedRow } from '@/types/import';

/**
 * A column of an imported file as the AI sees it: its header and a short
 * description of what its values look like. Never a value itself — that is
 * the whole privacy guarantee of the column-mapping suggestions. A header
 * that may be a value (one that reads as a date, an amount, an IBAN or a
 * currency code, or any header of a file without a header row) is null.
 */
export interface ColumnDescription {
    header: string | null;
    shape: string;
}

/** How many rows a shape is worked out from, spread across the file. */
const SAMPLE_ROWS = 200;

/** A currency symbol or ISO code a bank export may write next to an amount. */
const CURRENCY = /[€$£¥₹₽₺₩₪฿₫₴₦₱₲₵₡₸₼₾]|zł|kr|Kč|Ft|lei|\b[A-Z]{3}\b/u;
const EVERY_CURRENCY = new RegExp(CURRENCY.source, 'gu');

const SEPARATED_DATE =
    /^(\d{1,4})[./-](\d{1,2})[./-](\d{1,4})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?Z?)?$/;
const COMPACT_DATE = /^(?:19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])$/;
const MONTH_NAME_DATE =
    /^(?:\d{1,2}[ .-]+\p{L}{3,}\.?[ .-]+\d{2,4}|\p{L}{3,}\.? \d{1,2},? \d{4})$/u;
const TIME = /^\d{1,2}:\d{2}(?::\d{2})?$/;
const CURRENCY_CODE = /^[A-Z]{3}$/;
const IBAN = /^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/;

const ALL_POSITIVE = 'all positive';

/** The non-empty cells of a column, as trimmed text. */
function cellTexts(rows: ParsedRow[], header: string): string[] {
    return rows
        .map((row) => row[header])
        .map((cell) =>
            cell === null || cell === undefined ? '' : String(cell).trim(),
        )
        .filter((text) => text !== '');
}

/** At most {@link SAMPLE_ROWS} values, spread across the whole column. */
function sample(values: string[]): string[] {
    const step = Math.max(1, Math.ceil(values.length / SAMPLE_ROWS));

    return values.filter((_, index) => index % step === 0);
}

/** "dd/mm/yyyy", "mm/dd/yyyy" or "yyyy-mm-dd", from the order the parts allow. */
function separatedDateShape(values: string[]): string | null {
    const parts = values.map((value) => SEPARATED_DATE.exec(value));

    if (parts.some((match) => match === null)) {
        return null;
    }

    const matches = parts as RegExpExecArray[];
    const separator = /[./-]/.exec(values[0])?.[0] ?? '/';
    const withTime = values.some((value) => /\d:\d/.test(value));
    const suffix = withTime ? ' with time' : '';

    if (matches.every((match) => match[1].length === 4)) {
        return `date (yyyy${separator}mm${separator}dd${suffix})`;
    }

    const year = matches[0][3].length === 2 ? 'yy' : 'yyyy';
    const firstOverTwelve = matches.some((match) => Number(match[1]) > 12);
    const secondOverTwelve = matches.some((match) => Number(match[2]) > 12);
    const order = firstOverTwelve
        ? `dd${separator}mm`
        : secondOverTwelve
          ? `mm${separator}dd`
          : `dd${separator}mm or mm${separator}dd`;

    return `date (${order}${separator}${year}${suffix})`;
}

function dateShape(values: string[]): string | null {
    if (values.every((value) => COMPACT_DATE.test(value))) {
        return 'date (yyyymmdd)';
    }

    if (values.every((value) => MONTH_NAME_DATE.test(value))) {
        return 'date (with month name)';
    }

    return separatedDateShape(values);
}

/** A number as written, once its currency and grouping are taken off. */
interface ReadNumber {
    negative: boolean;
    decimals: number;
    decimalSeparator: ',' | '.' | null;
    integerDigits: number;
    withCurrency: boolean;
}

function readNumber(value: string): ReadNumber | null {
    const withCurrency = CURRENCY.test(value);
    let text = value.replace(EVERY_CURRENCY, '').replace(/[\s\u00a0']/g, '');

    const parenthesised = /^\(.*\)$/.test(text);
    text = text.replace(/^\((.*)\)$/, '$1');

    const negative = parenthesised || /^[-−]|-$/.test(text);
    text = text.replace(/^[+\-−]|-$/g, '');

    if (!/^\d[\d.,]*$/.test(text)) {
        return null;
    }

    const decimal = /[.,](\d{1,2})$/.exec(text);
    // "1.850" reads as a thousand in a comma-decimal file: three digits after
    // the only separator are a group, not decimals.
    const decimals = decimal ? decimal[1].length : 0;
    const integer = decimal ? text.slice(0, decimal.index) : text;

    return {
        negative,
        decimals,
        decimalSeparator: decimal ? (text[decimal.index] as ',' | '.') : null,
        integerDigits: integer.replace(/[.,]/g, '').length,
        withCurrency,
    };
}

/**
 * A spreadsheet keeps a date as the days since 1900, and the reader leaves it
 * that way: 1970 to 2100 is 25569 to 73051.
 */
function isSpreadsheetDate(value: string): boolean {
    const serial = Number(value);

    return Number.isInteger(serial) && serial >= 25569 && serial <= 73051;
}

/**
 * Which way the amounts go, read over every value rather than the sample: a
 * column that is all positive but for a refund or two is not all positive.
 */
function signOf(values: string[]): string {
    const negatives = values.filter(
        (value) => readNumber(value)?.negative,
    ).length;

    if (negatives === 0) {
        return ALL_POSITIVE;
    }

    return negatives === values.length ? 'all negative' : 'mixed signs';
}

function numberShape(values: string[], everyValue: string[]): string | null {
    const numbers = values.map(readNumber);

    if (numbers.some((number) => number === null)) {
        return null;
    }

    const read = numbers as ReadNumber[];
    const negatives = read.filter((number) => number.negative).length;
    const sign = signOf(everyValue);
    const maxDecimals = Math.max(...read.map((number) => number.decimals));

    if (maxDecimals === 0 && values.every(isSpreadsheetDate)) {
        return 'date (spreadsheet serial number)';
    }

    if (maxDecimals === 0) {
        const idLike =
            negatives === 0 &&
            read.every((number) => number.integerDigits >= 5) &&
            new Set(values).size === values.length;

        return idLike ? 'integer id-like' : `integer, ${sign}`;
    }

    const separator = read.find(
        (number) => number.decimalSeparator,
    )?.decimalSeparator;
    const currency = read.some((number) => number.withCurrency)
        ? ', with currency symbol'
        : '';

    return `number, ${maxDecimals} decimals with "${separator}"${currency}, ${sign}`;
}

function textShape(values: string[]): string {
    const distinct = new Set(values).size;
    const averageLength = Math.round(
        values.reduce((total, value) => total + value.length, 0) /
            values.length,
    );
    const variety =
        distinct === 1
            ? 'same value repeated'
            : distinct <= 10 && distinct < values.length
              ? `${distinct} distinct values`
              : distinct <= values.length / 2
                ? 'repeating values'
                : 'mostly distinct values';

    return `text, avg ${averageLength} chars, ${variety}`;
}

/** Every amount of a column with this shape is positive: no sign says which way it went. */
export function isAllPositive(shape: string): boolean {
    return shape.endsWith(`, ${ALL_POSITIVE}`);
}

/**
 * What a column's values look like, in a few words: "date (dd/mm/yyyy)",
 * "number, 2 decimals with ",", mixed signs", "text, avg 24 chars, mostly
 * distinct values". Built from a sample of the values (the sign from all of
 * them) but never quoting one.
 */
export function columnShape(
    values: string[],
    everyValue: string[] = values,
): string {
    if (values.length === 0) {
        return 'empty';
    }

    if (values.every((value) => TIME.test(value))) {
        return 'time';
    }

    if (values.every((value) => CURRENCY_CODE.test(value))) {
        return 'three-letter uppercase code';
    }

    if (values.every((value) => IBAN.test(value.replace(/\s/g, '')))) {
        return 'IBAN';
    }

    return (
        dateShape(values) ??
        numberShape(values, everyValue) ??
        textShape(values)
    ).slice(0, 120);
}

/** What kind of value a cell holds, when it holds one rather than a word. */
type ValueKind = 'time' | 'code' | 'iban' | 'number' | 'date';

/**
 * The kind of value a text is on its own, or null for a word: what tells a
 * header that is really a cell (the first movement of a file without a
 * header row) from one that names its column. "Concepto", "Fecha valor" and
 * "Importe (EUR)" are words; "01/12/2025", "-45,20", "EUR" and an IBAN are
 * values. A number comes before a date: "20251201" reads as both.
 */
export function valueKind(text: string): ValueKind | null {
    const value = text.trim();

    if (value === '') {
        return null;
    }

    if (TIME.test(value)) {
        return 'time';
    }

    if (CURRENCY_CODE.test(value)) {
        return 'code';
    }

    if (IBAN.test(value.replace(/\s/g, ''))) {
        return 'iban';
    }

    if (readNumber(value) !== null) {
        return 'number';
    }

    return isDateText(value) ? 'date' : null;
}

/** A spreadsheet's own number (a date there is one too) or a text's kind. */
function cellKind(cell: unknown): ValueKind | null {
    if (typeof cell === 'number') {
        return 'number';
    }

    return typeof cell === 'string' ? valueKind(cell) : null;
}

/** A cell that holds something, not an empty or missing one. */
function isFilled(cell: unknown): boolean {
    return cell !== null && cell !== undefined && String(cell).trim() !== '';
}

/**
 * Whether the row the reader took for headers (`columns` are the file's raw
 * columns, every row included) really is one. It is not when one of its
 * cells is a value of the kind most of its column holds — a date over the
 * dates, an amount over the amounts —, which is how a file that starts with
 * its first movement looks: the merchant name next to that date is a cell
 * too, and nothing in its shape would say so. Nor is it when nothing sits
 * below it: a file of one movement and no header row has no column to
 * compare it with, and its texts must not go out as headers.
 */
export function isHeaderRow(
    columns: unknown[][],
    headerRowIndex: number,
): boolean {
    const valuesBelow = columns.map((column) =>
        column
            .slice(headerRowIndex + 1)
            .filter(isFilled)
            .map(String),
    );

    if (valuesBelow.every((values) => values.length === 0)) {
        return false;
    }

    return !columns.some((column, index) => {
        const kind = cellKind(column[headerRowIndex]);

        if (kind === null) {
            return false;
        }

        const below = sample(valuesBelow[index]);
        const alike = below.filter((cell) => cellKind(cell) === kind).length;

        return below.length > 0 && alike / below.length >= 0.5;
    });
}

/**
 * Every column of the file, by header and shape, in file order. A header is
 * only sent when the file has a header row (see {@link isHeaderRow}) and the
 * header itself reads as a word, not a value (see {@link valueKind}).
 */
export function describeColumns(
    headers: string[],
    rows: ParsedRow[],
    hasHeaderRow: boolean,
): ColumnDescription[] {
    return headers.map((header) => {
        const values = cellTexts(rows, header);

        return {
            header:
                hasHeaderRow && valueKind(header) === null
                    ? truncateText(header, 120)
                    : null,
            shape: columnShape(sample(values), values),
        };
    });
}
