import { parseAmount, parseDate } from '@/lib/file-parser';
import {
    balanceOf,
    cellText,
    headerLookup,
    MAX_NOTES_LENGTH,
    normalizeText,
    sameText,
    splitCategoryPath,
    unreadableReason,
    type NormalizeOptions,
    type RowReader,
} from '@/lib/full-import-profiles';
import { truncateText } from '@/lib/utils';
import { type Category } from '@/types/category';
import {
    type FullImportMapping,
    type NormalizedRow,
    type UnreadableRow,
} from '@/types/full-import';
import { type ParsedRow } from '@/types/import';
import { getCurrencySymbol } from '@/utils/currency';
import { formatLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** What a Money Pro row is, from its transaction type. */
type MoneyProType = 'expense' | 'income' | 'transfer' | 'balance';

/**
 * Every transaction type Money Pro writes, by its normalised name, in each
 * language its export is known in. Another language is one more line each.
 */
const MONEY_PRO_TYPES: Record<string, MoneyProType> = {
    gasto: 'expense',
    ingreso: 'income',
    'transferencia de dinero': 'transfer',
    'ajuste de saldo': 'balance',
    'saldo de apertura': 'balance',
    expense: 'expense',
    income: 'income',
    'money transfer': 'transfer',
    'balance adjustment': 'balance',
    'opening balance': 'balance',
};

/** The columns the mapping has no field for, by their normalised names. */
const MONEY_PRO_EXTRA_COLUMNS = {
    type: 'tipo de transaccion',
    received: 'monto recibido',
    destination: 'cuenta de destino',
};

/**
 * The currency an amount cell is written in, as the file writes it: what is
 * left once the digits, separators and signs are gone ("8.400,00 €" is "€").
 */
export function currencySymbolOf(cell: string): string {
    return cell.replace(/[\d\s.,'+\-−()]/g, '');
}

function narrowSymbol(code: string): string | null {
    try {
        return (
            new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: code,
                currencyDisplay: 'narrowSymbol',
            })
                .formatToParts(0)
                .find((part) => part.type === 'currency')?.value ?? null
        );
    } catch {
        return null;
    }
}

/** Every supported currency written with this symbol. */
function symbolCandidates(
    symbol: string,
    supportedCurrencies: readonly string[],
): string[] {
    return supportedCurrencies.filter(
        (one) =>
            getCurrencySymbol(one) === symbol || narrowSymbol(one) === symbol,
    );
}

/**
 * The ISO code a currency symbol most likely stands for. "$" is a dollar or
 * a peso: the user's own currency when it is one of them, the US dollar
 * otherwise. A symbol nothing writes that way stays unknown, and its rows
 * go to the account's currency.
 */
export function defaultSymbolCurrency(
    symbol: string,
    supportedCurrencies: readonly string[],
    userCurrency?: string,
): string | null {
    const code = symbol.toUpperCase();

    if (supportedCurrencies.includes(code)) {
        return code;
    }

    const candidates = symbolCandidates(symbol, supportedCurrencies);

    if (userCurrency && candidates.includes(userCurrency)) {
        return userCurrency;
    }

    return (
        candidates.find((one) => getCurrencySymbol(one) === symbol) ??
        candidates[0] ??
        null
    );
}

/** A currency symbol of the file, how many amounts carry it and what it is read as. */
export interface CurrencySymbolUse {
    symbol: string;
    count: number;
    code: string | null;
    /** Several currencies write it ("$"), so the default is a guess. */
    ambiguous: boolean;
}

type CurrencyOptions = Pick<
    NormalizeOptions,
    'supportedCurrencies' | 'userCurrency'
>;

/** The ISO code a symbol is read as: the user's pick, else its default. */
function symbolCurrency(
    symbol: string,
    mapping: FullImportMapping,
    options: CurrencyOptions,
): string | null {
    if (symbol === '') {
        return null;
    }

    return (
        mapping.currencySymbols[symbol] ??
        defaultSymbolCurrency(
            symbol,
            options.supportedCurrencies,
            options.userCurrency,
        )
    );
}

/**
 * Every currency symbol the amounts of a Money Pro file are written in, the
 * most used first, with the ISO code each one is read as.
 */
export function collectCurrencySymbols(
    rows: ParsedRow[],
    headers: string[],
    mapping: FullImportMapping,
    options: CurrencyOptions,
): CurrencySymbolUse[] {
    const received =
        headerLookup(headers).get(MONEY_PRO_EXTRA_COLUMNS.received) ?? null;
    const tally = new Map<string, number>();

    for (const row of rows) {
        for (const column of [mapping.amount, received]) {
            const symbol = currencySymbolOf(cellText(row, column));

            if (symbol !== '') {
                tally.set(symbol, (tally.get(symbol) ?? 0) + 1);
            }
        }
    }

    return [...tally.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([symbol, count]) => ({
            symbol,
            count,
            code: symbolCurrency(symbol, mapping, options),
            ambiguous:
                symbolCandidates(symbol, options.supportedCurrencies).length >
                1,
        }));
}

/**
 * A category as Money Pro's cells are compared: "Parent : Child" and
 * "Parent:Child" both read "parent: child", case, accents and runs of
 * spaces aside.
 */
export function moneyProCategoryKey(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s*:\s*/g, ': ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** What the reader knows of the categories, besides the row it reads. */
export interface MoneyProCategoryContext {
    /** Every row of the file: the categories it writes alone, and how often. */
    rows?: ParsedRow[];
    /** The user's own categories. */
    categories?: Pick<Category, 'id' | 'name' | 'parent_id'>[];
}

/** The categories a cell can be told apart into. */
export interface CategoryVocabulary {
    /** The user's categories: a root by its name, a child as "Parent: Child". */
    existing: Set<string>;
    /** Those, and every cell of the file with at most one parent in it. */
    known: Set<string>;
    /**
     * The parents those name ("familia" of "Familia: Colegio"), with how many
     * of them name it: the user's own count as many.
     */
    parents: Map<string, number>;
    /** How many rows of the file carry each cell. */
    cellCounts: Map<string, number>;
    /**
     * The most words a piece of a cell is read in: the longest known
     * category's, and never under two ("parent: child"). A longer piece
     * cuts no more categories than shorter ones do.
     */
    longestPiece: number;
    /** The verdict on each cell already read, by its key. */
    verdicts: Map<string, boolean>;
}

/** "familia" of "familia: colegio", null for a key without a parent. */
function parentOf(key: string): string | null {
    const colon = key.indexOf(':');

    return colon < 0 ? null : key.slice(0, colon);
}

function colonCount(key: string): number {
    return key.split(':').length - 1;
}

/** Everything a Money Pro file and the user's categories name. */
export function categoryVocabulary(
    context: MoneyProCategoryContext,
    categoryColumn: string | null,
): CategoryVocabulary {
    const byId = new Map(
        (context.categories ?? []).map((category) => [category.id, category]),
    );
    const existing = new Set<string>();
    const parents = new Map<string, number>();

    for (const category of byId.values()) {
        const parent = category.parent_id ? byId.get(category.parent_id) : null;

        // A child by its full path only: a bare "Online" under "Compras"
        // would cut a cell like "Suscripciones Online" in two.
        if (parent) {
            existing.add(
                moneyProCategoryKey(`${parent.name}: ${category.name}`),
            );
            parents.set(moneyProCategoryKey(parent.name), Infinity);
        } else {
            existing.add(moneyProCategoryKey(category.name));
        }
    }

    const cellCounts = new Map<string, number>();

    for (const row of context.rows ?? []) {
        const key = moneyProCategoryKey(cellText(row, categoryColumn));

        if (key !== '') {
            cellCounts.set(key, (cellCounts.get(key) ?? 0) + 1);
        }
    }

    const known = new Set(existing);

    for (const key of cellCounts.keys()) {
        if (colonCount(key) <= 1) {
            known.add(key);
        }

        const parent = colonCount(key) === 1 ? parentOf(key) : null;

        if (parent) {
            parents.set(parent, (parents.get(parent) ?? 0) + 1);
        }
    }

    let longestPiece = 2;

    for (const key of known) {
        longestPiece = Math.max(longestPiece, key.split(' ').length);
    }

    return {
        existing,
        known,
        parents,
        cellCounts,
        longestPiece,
        verdicts: new Map(),
    };
}

/**
 * The most categories the cell's words can be cut into, the cell itself
 * aside: `all` over cuts where every piece is known ("Compras Compras
 * Salud"), `some` over cuts that leave pieces nothing names in between,
 * counting the ones only `recognised` too. A piece ends on a name, never on
 * the colon after a parent, and has at most `longestPiece` words: no known
 * category is longer, a recognised one ("parent: child") already counts at
 * its shortest, and the words past it count nothing either way. -1 is no
 * such cut.
 */
function knownPieces(
    words: string[],
    longestPiece: number,
    known: (piece: string) => boolean,
    recognised: (piece: string) => boolean,
): { all: number; some: number } {
    const all: number[] = [0];
    const some: number[] = [0];

    for (let end = 1; end <= words.length; end++) {
        all[end] = -1;
        some[end] = -1;

        if (words[end - 1].endsWith(':')) {
            continue;
        }

        for (
            let start = Math.max(0, end - longestPiece);
            start < end;
            start++
        ) {
            if (some[start] < 0 || (start === 0 && end === words.length)) {
                continue;
            }

            const piece = words.slice(start, end).join(' ');
            const isKnown = known(piece);
            const counts = isKnown || recognised(piece) ? 1 : 0;

            some[end] = Math.max(some[end], some[start] + counts);

            if (isKnown && all[start] >= 0) {
                all[end] = Math.max(all[end], all[start] + 1);
            }
        }
    }

    return { all: all[words.length], some: some[words.length] };
}

/**
 * Whether a Money Pro category cell holds the categories of a split rather
 * than one category: Money Pro writes every line's category into the one
 * cell, space after space, and no amount for each. It does when the cell
 * names more than one parent, when its words cut into categories the file
 * writes alone or the user has, or when it is a one-off that cuts into at
 * least two of those with something else in between. A category the user
 * has is one, whatever its words. Reading a split as one category would
 * make up a category; the other way round only leaves a row to categorize.
 */
export function isMultiCategoryCell(
    cell: string,
    vocabulary: CategoryVocabulary,
): boolean {
    const key = moneyProCategoryKey(cell);

    if (colonCount(key) >= 2) {
        return true;
    }

    if (key === '' || vocabulary.existing.has(key)) {
        return false;
    }

    const verdict =
        vocabulary.verdicts.get(key) ?? cutsIntoSeveral(key, vocabulary);

    vocabulary.verdicts.set(key, verdict);

    return verdict;
}

/** Whether a cell of at most one parent, not the user's, cuts in several. */
function cutsIntoSeveral(key: string, vocabulary: CategoryVocabulary): boolean {
    const known = (piece: string) => vocabulary.known.has(piece);
    // In a one-off, "Familia: Libros" reads as a category of its own even if
    // nothing else writes it, under a parent another cell or the user has.
    const ownParent = vocabulary.cellCounts.has(key) ? parentOf(key) : null;
    const pieces = knownPieces(
        key.split(' '),
        vocabulary.longestPiece,
        known,
        (piece) => {
            const parent = parentOf(piece);
            const named =
                parent === null ? 0 : (vocabulary.parents.get(parent) ?? 0);

            return named - (parent === ownParent ? 1 : 0) > 0;
        },
    );

    return (
        pieces.all >= 2 ||
        (pieces.some >= 2 && (vocabulary.cellCounts.get(key) ?? 0) <= 1)
    );
}

/**
 * The note a split row carries: Money Pro's categories, after its own. A
 * long note of its own is cut first, so the categories always fit in what
 * a row may send.
 */
function splitNotes(notes: string | null, cell: string): string {
    const note = __('Split in Money Pro: :categories', { categories: cell });
    const room = MAX_NOTES_LENGTH - note.length - 1;

    return notes && room > 0 ? `${truncateText(notes, room)}\n${note}` : note;
}

/** A Money Pro account is a name the user gave it, never a bank's. */
function account(
    name: string,
): Pick<NormalizedRow, 'accountKey' | 'accountName' | 'accountBank'> {
    return {
        accountKey: normalizeText(name),
        accountName: name || __('No account'),
        accountBank: '',
    };
}

/**
 * The reader of a Money Pro export. Its amounts are always positive: the
 * transaction type gives the sign. A transfer is one row for two movements,
 * out of `Cuenta` and into `Cuenta de destino`, each in its own currency. An
 * opening balance or an adjustment imports no transaction, only the balance
 * the account was left with, which `Saldo` carries on every row.
 */
export function moneyProReader(
    headers: string[],
    categoryContext: MoneyProCategoryContext = {},
): RowReader {
    const byName = headerLookup(headers);
    const column = (name: string) => byName.get(name) ?? null;
    const typeColumn = column(MONEY_PRO_EXTRA_COLUMNS.type);
    const receivedColumn = column(MONEY_PRO_EXTRA_COLUMNS.received);
    const destinationColumn = column(MONEY_PRO_EXTRA_COLUMNS.destination);
    // Worked out once per symbol, and again for a new mapping (the user
    // picked another currency for one) or new options (another reading).
    const symbols = new Map<string, string | null>();
    let symbolsFor: [FullImportMapping, NormalizeOptions] | null = null;
    // Read off the whole file once per category column.
    let vocabulary: [string | null, CategoryVocabulary] | null = null;

    return (row, rowNumber, mapping, options) => {
        if (symbolsFor?.[0] !== mapping || symbolsFor[1] !== options) {
            symbols.clear();
            symbolsFor = [mapping, options];
        }

        if (vocabulary?.[0] !== mapping.category) {
            vocabulary = [
                mapping.category,
                categoryVocabulary(categoryContext, mapping.category),
            ];
        }

        const currencyOf = (cell: string) => {
            const symbol = currencySymbolOf(cell);

            if (!symbols.has(symbol)) {
                symbols.set(symbol, symbolCurrency(symbol, mapping, options));
            }

            return symbols.get(symbol) ?? null;
        };
        const typeText = cellText(row, typeColumn);
        const type = MONEY_PRO_TYPES[normalizeText(typeText)];

        if (!type) {
            return [
                {
                    rowNumber,
                    reason: typeText
                        ? __('Unknown type «:type»', { type: typeText })
                        : __('No type'),
                },
            ];
        }

        const date = mapping.date
            ? parseDate(
                  row[mapping.date] as string | number,
                  mapping.dateFormat,
              )
            : null;
        const amountCell = cellText(row, mapping.amount);
        const amount = parseAmount(amountCell);
        const categoryCell = cellText(row, mapping.category);
        const split = isMultiCategoryCell(categoryCell, vocabulary[1]);
        const categoryPath = split
            ? []
            : splitCategoryPath(categoryCell, mapping.categorySeparator);
        const description =
            cellText(row, mapping.description) ||
            (split ? categoryCell : categoryPath.at(-1)) ||
            typeText;
        // A balance-only row needs no amount: its balance is all it brings.
        const reason = unreadableReason(
            row,
            mapping,
            date,
            type === 'balance' ? (amount ?? 0) : amount,
            description,
        );

        if (reason !== null || !date) {
            return [{ rowNumber, reason: reason ?? __('No date') }];
        }

        const notesCell = cellText(row, mapping.notes);
        const notes =
            notesCell && !sameText(notesCell, description) ? notesCell : null;
        const source = {
            rowNumber,
            date: formatLocalDate(date),
            description,
            notes: split ? splitNotes(notes, categoryCell) : notes,
            iban: null,
            externalId: null,
            ignored: false,
            ...account(cellText(row, mapping.account)),
            currency: currencyOf(amountCell || cellText(row, mapping.balance)),
            balance: balanceOf(row, mapping),
        };
        const size = Math.abs(amount ?? 0);

        if (type === 'balance') {
            return source.balance === null
                ? []
                : [
                      {
                          ...source,
                          kind: 'balance',
                          amount: 0,
                          categoryPath: [],
                      },
                  ];
        }

        if (type !== 'transfer') {
            return [
                {
                    ...source,
                    kind: 'movement',
                    amount: type === 'expense' ? -size : size,
                    categoryPath,
                    ...(split ? { sourceSplit: true as const } : {}),
                },
            ];
        }

        return transferLegs(source, size, {
            destination: cellText(row, destinationColumn),
            receivedCell: cellText(row, receivedColumn),
            currencyOf,
        });
    };
}

/** Both movements of a transfer: out of the account, into the destination. */
function transferLegs(
    source: Omit<NormalizedRow, 'kind' | 'amount' | 'categoryPath'>,
    size: number,
    target: {
        destination: string;
        receivedCell: string;
        currencyOf: (cell: string) => string | null;
    },
): (NormalizedRow | UnreadableRow)[] {
    if (target.destination === '') {
        return [
            {
                rowNumber: source.rowNumber,
                reason: __('No destination account'),
            },
        ];
    }

    const received = parseAmount(target.receivedCell);

    // Both legs read the same, so each names the account on the other side.
    return [
        {
            ...source,
            kind: 'transfer',
            amount: -size,
            categoryPath: [],
            notes: __('To :account', { account: target.destination }),
        },
        {
            ...source,
            ...account(target.destination),
            kind: 'transfer',
            notes: __('From :account', { account: source.accountName }),
            amount: received === null ? size : Math.abs(received),
            categoryPath: [],
            currency:
                received === null
                    ? source.currency
                    : target.currencyOf(target.receivedCell),
            balance: null,
        },
    ];
}
