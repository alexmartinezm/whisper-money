import {
    categoryVocabulary,
    collectCurrencySymbols,
    currencySymbolOf,
    defaultSymbolCurrency,
    isMultiCategoryCell,
    moneyProReader,
} from '@/lib/full-import-money-pro';
import {
    buildImport,
    categoryNodeId,
    detectAccounts,
    detectCategories,
    OWN_TRANSFER_KEY,
    resolveCategoryPlan,
    type BuildImportInput,
} from '@/lib/full-import-plan';
import {
    defaultMapping,
    detectSource,
    normalizeRows,
} from '@/lib/full-import-profiles';
import { parseImportFile } from '@/lib/transaction-import';
import {
    type FullImportMapping,
    type TransferTarget,
} from '@/types/full-import';
import { DateFormat } from '@/types/import';
import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';

const SUPPORTED = ['EUR', 'USD', 'COP', 'GBP', 'MXN'];

/** A synthetic Money Pro export: made-up accounts, no real person in it. */
function moneyProFile(): File {
    return new File(
        [
            new Uint8Array(
                readFileSync(
                    'resources/js/lib/__fixtures__/money-pro-sample.csv',
                ),
            ),
        ],
        'money-pro.csv',
        { type: 'text/csv' },
    );
}

async function readMoneyPro(
    userCurrency = 'EUR',
    patch: Partial<FullImportMapping> = {},
) {
    const parsed = await parseImportFile(moneyProFile(), 'es-ES');
    const mapping = {
        ...defaultMapping('money_pro', parsed, 'es-ES'),
        ...patch,
    };
    const options = {
        fileName: 'money-pro.csv',
        supportedCurrencies: SUPPORTED,
        userCurrency,
    };
    const normalized = normalizeRows(
        parsed,
        mapping,
        options,
        moneyProReader(parsed.headers),
    );

    return { parsed, mapping, options, normalized };
}

describe('reading a Money Pro export', () => {
    it('is recognised by its header signature and mapped whole', async () => {
        const { parsed, mapping } = await readMoneyPro();

        expect(detectSource(parsed.headers)).toBe('money_pro');
        expect(mapping).toMatchObject({
            date: 'Fecha',
            amount: 'Cantidad',
            account: 'Cuenta',
            description: 'Descripción',
            category: 'Categoría',
            balance: 'Saldo',
            categorySeparator: ':',
            dateFormat: DateFormat.DayMonthYear,
        });
    });

    it('signs expenses and income by their type, with a description fallback', async () => {
        const { normalized } = await readMoneyPro();
        const movements = normalized.rows.filter(
            (row) => row.kind === 'movement',
        );

        expect(normalized.unreadable).toEqual([]);
        expect(movements[0]).toMatchObject({
            date: '2018-02-25',
            amount: 58000,
            description: 'Otros',
            categoryPath: ['Otros'],
            accountName: 'Wallet',
            accountBank: '',
            currency: 'EUR',
            balance: 58000,
        });
        expect(movements[1]).toMatchObject({
            date: '2018-02-26',
            amount: -8400,
            description: 'Toll',
            categoryPath: ['Vehículo', 'Peaje'],
            balance: 49600,
        });
    });

    it('reads a transfer as two movements, each in its own currency', async () => {
        const { normalized } = await readMoneyPro();
        const legs = normalized.rows.filter((row) => row.kind === 'transfer');

        expect(legs).toHaveLength(2);
        expect(legs[0]).toMatchObject({
            accountKey: 'wallet',
            amount: -750,
            currency: 'EUR',
            balance: 48850,
            description: 'Transferencia de dinero',
            notes: 'To Safe box',
            categoryPath: [],
        });
        expect(legs[1]).toMatchObject({
            accountKey: 'safe box',
            accountName: 'Safe box',
            amount: 750,
            currency: 'USD',
            balance: null,
            notes: 'From Wallet',
        });
    });

    it('keeps opening balances and adjustments as balances only', async () => {
        const { normalized } = await readMoneyPro();
        const balances = normalized.rows.filter(
            (row) => row.kind === 'balance',
        );

        expect(balances).toMatchObject([
            { accountName: 'Wallet', amount: 0, balance: 0, currency: 'EUR' },
            {
                accountName: 'Savings',
                amount: 0,
                balance: 8000000,
                date: '2018-01-30',
            },
        ]);
    });

    it('keeps the balance of an adjustment that has no amount', async () => {
        const parsed = await parseImportFile(
            new File(
                [
                    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción\n25/2/18, 4:57;;Wallet;;;120,00 €;;;Ajuste de saldo\n',
                ],
                'adjustment.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            defaultMapping('money_pro', parsed, 'es-ES'),
            { fileName: 'adjustment.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers),
        );

        expect(normalized.unreadable).toEqual([]);
        expect(normalized.rows).toMatchObject([
            { kind: 'balance', balance: 120, currency: 'EUR' },
        ]);
    });

    it('turns down a row whose type it does not know', async () => {
        const parsed = await parseImportFile(
            new File(
                [
                    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción\n25/2/18, 4:57;5,00 €;Wallet;;;5,00 €;;;Reembolso\n',
                ],
                'odd.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            defaultMapping('money_pro', parsed, 'es-ES'),
            { fileName: 'odd.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers),
        );

        expect(normalized.rows).toEqual([]);
        expect(normalized.unreadable).toEqual([
            { rowNumber: 2, reason: 'Unknown type «Reembolso»' },
        ]);
    });
});

describe('currency symbols', () => {
    it('takes the symbol out of an amount cell', () => {
        expect(currencySymbolOf('8.400,00 €')).toBe('€');
        expect(currencySymbolOf('-$1,200.50')).toBe('$');
        expect(currencySymbolOf('1.000,00 COP')).toBe('COP');
        expect(currencySymbolOf('12')).toBe('');
    });

    it('reads $ as the user currency when it is a dollar or a peso, else USD', () => {
        expect(defaultSymbolCurrency('$', SUPPORTED, 'COP')).toBe('COP');
        expect(defaultSymbolCurrency('$', SUPPORTED, 'MXN')).toBe('MXN');
        expect(defaultSymbolCurrency('$', SUPPORTED, 'EUR')).toBe('USD');
        expect(defaultSymbolCurrency('€', SUPPORTED, 'USD')).toBe('EUR');
        expect(defaultSymbolCurrency('£', SUPPORTED, 'EUR')).toBe('GBP');
        expect(defaultSymbolCurrency('cop', SUPPORTED, 'EUR')).toBe('COP');
        expect(defaultSymbolCurrency('₿', SUPPORTED, 'EUR')).toBeNull();
    });

    it('lists the symbols the file uses with what each is read as', async () => {
        const { parsed, mapping, options } = await readMoneyPro('COP');

        expect(
            collectCurrencySymbols(
                parsed.rows,
                parsed.headers,
                mapping,
                options,
            ),
        ).toEqual([
            { symbol: '€', count: 4, code: 'EUR', ambiguous: false },
            { symbol: '$', count: 3, code: 'COP', ambiguous: true },
        ]);
    });

    it('follows the currency the user picked for a symbol', async () => {
        const { normalized } = await readMoneyPro('EUR', {
            currencySymbols: { $: 'COP' },
        });

        expect(
            normalized.rows
                .filter((row) => row.accountName === 'Savings')
                .map((row) => row.currency),
        ).toEqual(['COP', 'COP']);
    });
});

describe('importing a Money Pro export', () => {
    it('sends no transaction for a balance-only row, and transfers as own transfers', async () => {
        const { normalized, mapping } = await readMoneyPro('COP');
        const fileAccounts = detectAccounts(normalized.rows);
        const plan = (name: string, currencyCode: string) => ({
            action: 'create' as const,
            name,
            type: 'checking' as const,
            currencyCode,
            bank: null,
            newBankName: null,
            targetAccountId: null,
            mergeIntoKey: null,
            archive: false,
        });
        const input: BuildImportInput = {
            source: 'money_pro',
            fileName: 'money-pro.csv',
            mode: 'add',
            mapping,
            rows: normalized.rows,
            fileAccounts,
            accountPlan: {
                wallet: plan('Wallet', 'EUR'),
                savings: plan('Savings', 'COP'),
                'safe box': plan('Safe box', 'COP'),
            },
            inactiveAccountKeys: new Set(),
            contextAccounts: [],
            nodes: [],
            categoryPlan: {},
            categories: [],
            transfers: {
                own: {
                    categoryId: 'own-category',
                    target: {
                        category_id: 'own-category',
                        name: 'Own transfers',
                        icon: 'Split',
                        color: 'stone',
                    },
                },
                ignored: {
                    categoryId: null,
                    target: {
                        category_id: null,
                        name: 'Ignored',
                        icon: 'Split',
                        color: 'stone',
                    },
                },
            },
        };

        expect(
            Object.fromEntries(
                fileAccounts.map((account) => [account.name, account.count]),
            ),
        ).toEqual({ Wallet: 3, Savings: 1, 'Safe box': 1 });

        const built = buildImport(input);
        const savingsKey = `a${fileAccounts.findIndex((account) => account.name === 'Savings')}`;

        // This fork stores every currency in hundredths of its unit (COP
        // included), so 750 $ into the COP safe box is 75000 and the COP
        // balances carry two more digits than upstream's.
        expect(built.transactions).toHaveLength(5);
        expect(
            built.transactions
                .filter((row) => row.category_key === OWN_TRANSFER_KEY)
                .map((row) => row.amount),
        ).toEqual([-75000, 75000]);
        expect(
            built.balances.filter((row) => row.account_key === savingsKey),
        ).toEqual([
            { account_key: savingsKey, date: '2018-01-30', balance: 800000000 },
            { account_key: savingsKey, date: '2018-02-02', balance: 675000000 },
        ]);
    });
});

/**
 * A Money Pro export from its Spanish UI, which writes the month by name
 * ("1 dic 2025, 0:00:00"). Invented accounts and amounts.
 */
const MONTH_NAME_EXPORT = [
    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción;Agente;N.º de cheque;Clase;',
    '1 dic 2025, 0:00:00;1.000,00 €;Cuenta Ficticia;;;1.000,00 €;;;Saldo de apertura;;;;',
    '3 dic 2025, 17:02:00;45,90 €;Cuenta Ficticia;;;954,10 €;Empresa: Seguros;Póliza inventada;Gasto;;;;',
    '5 dic 2025, 14:00:00;1.250,00 €;Cuenta Ficticia;;;2.204,10 €;Salario;Nómina inventada;Ingreso;;;;',
    '8 ene 2026, 9:30:00;200,00 €;Cuenta Ficticia;200,00 €;Hucha Ficticia;2.004,10 €;;;Transferencia de dinero;;;;',
].join('\n');

describe('a Money Pro export with month names in its dates', () => {
    async function readMonthNames() {
        const parsed = await parseImportFile(
            new File([MONTH_NAME_EXPORT], 'money-pro.csv', {
                type: 'text/csv',
            }),
            'es-ES',
        );
        const mapping = defaultMapping('money_pro', parsed, 'es-ES');
        const normalized = normalizeRows(
            parsed,
            mapping,
            {
                fileName: 'money-pro.csv',
                supportedCurrencies: SUPPORTED,
                userCurrency: 'EUR',
            },
            moneyProReader(parsed.headers),
        );

        return { parsed, mapping, normalized };
    }

    it('reads every row, its dates, amounts, categories and the transfer', async () => {
        const { parsed, mapping, normalized } = await readMonthNames();

        expect(detectSource(parsed.headers)).toBe('money_pro');
        expect(mapping).toMatchObject({
            date: 'Fecha',
            dateFormat: DateFormat.DayMonthYear,
        });
        expect(normalized.unreadable).toEqual([]);
        expect(
            normalized.rows.map((row) => [
                row.kind,
                row.date,
                row.accountName,
                row.amount,
                row.categoryPath,
            ]),
        ).toEqual([
            ['balance', '2025-12-01', 'Cuenta Ficticia', 0, []],
            [
                'movement',
                '2025-12-03',
                'Cuenta Ficticia',
                -45.9,
                ['Empresa', 'Seguros'],
            ],
            ['movement', '2025-12-05', 'Cuenta Ficticia', 1250, ['Salario']],
            ['transfer', '2026-01-08', 'Cuenta Ficticia', -200, []],
            ['transfer', '2026-01-08', 'Hucha Ficticia', 200, []],
        ]);
        expect(normalized.rows[1]).toMatchObject({
            description: 'Póliza inventada',
            currency: 'EUR',
            balance: 954.1,
        });
    });

    it('builds the import with each row on its day, in minor units', async () => {
        const { mapping, normalized } = await readMonthNames();
        const fileAccounts = detectAccounts(normalized.rows);
        const nodes = detectCategories(normalized.rows);
        const plan = (name: string) => ({
            action: 'create' as const,
            name,
            type: 'checking' as const,
            currencyCode: 'EUR',
            bank: null,
            newBankName: null,
            targetAccountId: null,
            mergeIntoKey: null,
            archive: false,
        });
        const target = (name: string): TransferTarget => ({
            category_id: null,
            name,
            icon: 'Split',
            color: 'stone',
        });
        const built = buildImport({
            source: 'money_pro',
            fileName: 'money-pro.csv',
            mode: 'add',
            mapping,
            rows: normalized.rows,
            fileAccounts,
            accountPlan: {
                'cuenta ficticia': plan('Cuenta Ficticia'),
                'hucha ficticia': plan('Hucha Ficticia'),
            },
            inactiveAccountKeys: new Set(),
            contextAccounts: [],
            nodes,
            categoryPlan: resolveCategoryPlan(
                nodes,
                {},
                { categories: [], defaultNames: [] },
            ),
            categories: [],
            transfers: {
                own: { categoryId: null, target: target('Own transfers') },
                ignored: { categoryId: null, target: target('Ignored') },
            },
        });
        const subcategory = built.payload.categories.find(
            (entry) => entry.name === 'Seguros',
        );

        expect(built.transactions.map((row) => [row.date, row.amount])).toEqual(
            [
                ['2025-12-03', -4590],
                ['2025-12-05', 125000],
                ['2026-01-08', -20000],
                ['2026-01-08', 20000],
            ],
        );
        expect(
            built.transactions.slice(2).map((row) => row.category_key),
        ).toEqual([OWN_TRANSFER_KEY, OWN_TRANSFER_KEY]);
        expect(subcategory).toMatchObject({
            action: 'create',
            parent_key: built.payload.categories.find(
                (entry) => entry.name === 'Empresa',
            )?.key,
        });
        expect(built.transactions[0].category_key).toBe(subcategory?.key);
        expect(nodes.map((node) => node.id)).toContain(
            categoryNodeId(['Empresa', 'Seguros']),
        );
        expect(built.balances[0]).toMatchObject({
            date: '2025-12-01',
            balance: 100000,
        });
    });
});

/**
 * An invented Money Pro export where some rows were split across several
 * categories: Money Pro writes all of them in the one cell, without amounts.
 */
const SPLIT_EXPORT = [
    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción;Agente;N.º de cheque;Clase;',
    ...[
        ['1', 'Supermercado : Alimentación', 'Súper inventado'],
        ['2', 'Compras', 'Tienda inventada'],
        ['3', 'Salud', 'Farmacia inventada'],
        ['4', 'Restaurantes', ''],
        ['5', 'Familia: Colegio', 'Colegio inventado'],
        ['6', 'Suscripciones Online', 'Servicio inventado'],
        ['7', 'Empresa: Empresa', 'Gasto inventado'],
        [
            '8',
            'Familia: Ropa Supermercado : Hogar Supermercado : Alimentación',
            'Compra grande inventada',
        ],
        ['9', 'Restaurantes Restaurantes Restaurantes', ''],
        [
            '10',
            'Supermercado : Alimentación Compras Salud',
            'Compra mixta inventada',
        ],
        ['11', 'Compras Compras Salud', 'Otra compra inventada'],
        ['12', 'Coche: Gasolina Viajes: Playa', 'Viaje inventado'],
        ['13', 'Familia: Libros Papel Compras', 'Regalos inventados'],
        ['14', 'Supermercado: Alimentación', ''],
    ].map(
        ([day, category, description]) =>
            `${day} feb 2026, 10:00:00;10,00 €;Cuenta Ficticia;;;100,00 €;${category};${description};Gasto;;;;`,
    ),
    '15 feb 2026, 9:00:00;1.500,00 €;Cuenta Ficticia;;;1.600,00 €;Salario: Nómina;Nómina inventada;Ingreso;;;;',
].join('\n');

describe('a Money Pro export with split transactions', () => {
    async function readSplits(
        categories: {
            id: string;
            name: string;
            parent_id: string | null;
        }[] = [],
    ) {
        const parsed = await parseImportFile(
            new File([SPLIT_EXPORT], 'money-pro.csv', { type: 'text/csv' }),
            'es-ES',
        );
        const mapping = defaultMapping('money_pro', parsed, 'es-ES');
        const normalized = normalizeRows(
            parsed,
            mapping,
            {
                fileName: 'money-pro.csv',
                supportedCurrencies: SUPPORTED,
                userCurrency: 'EUR',
            },
            moneyProReader(parsed.headers, { rows: parsed.rows, categories }),
        );

        return { parsed, mapping, normalized };
    }

    const byDescription = <T extends { description: string }>(
        rows: T[],
        description: string,
    ): T | undefined => rows.find((row) => row.description === description);

    it('reads a cell of one category as before, whatever the spacing round its colon', async () => {
        const { normalized } = await readSplits();

        expect(normalized.unreadable).toEqual([]);
        expect(
            normalized.rows
                .filter((row) => !row.sourceSplit)
                .map((row) => [row.description, row.categoryPath]),
        ).toEqual([
            ['Súper inventado', ['Supermercado', 'Alimentación']],
            ['Tienda inventada', ['Compras']],
            ['Farmacia inventada', ['Salud']],
            ['Restaurantes', ['Restaurantes']],
            ['Colegio inventado', ['Familia', 'Colegio']],
            ['Servicio inventado', ['Suscripciones Online']],
            ['Gasto inventado', ['Empresa', 'Empresa']],
            ['Alimentación', ['Supermercado', 'Alimentación']],
            ['Nómina inventada', ['Salario', 'Nómina']],
        ]);
        expect(
            normalized.rows.filter((row) => !row.sourceSplit),
        ).not.toContainEqual(expect.objectContaining({ sourceSplit: true }));
    });

    it('takes a cell of several categories in whole, uncategorized, with the cell in its notes', async () => {
        const { normalized } = await readSplits();
        const split = normalized.rows.filter((row) => row.sourceSplit);

        expect(split.map((row) => row.description)).toEqual([
            'Compra grande inventada',
            // No description: the categories it had stand in for one.
            'Restaurantes Restaurantes Restaurantes',
            'Compra mixta inventada',
            'Otra compra inventada',
            'Viaje inventado',
            'Regalos inventados',
        ]);
        expect(
            split.every(
                (row) =>
                    row.kind === 'movement' &&
                    row.categoryPath.length === 0 &&
                    row.amount === -10,
            ),
        ).toBe(true);
        expect(byDescription(split, 'Compra mixta inventada')?.notes).toBe(
            'Split in Money Pro: Supermercado : Alimentación Compras Salud',
        );
        expect(
            byDescription(split, 'Restaurantes Restaurantes Restaurantes')
                ?.notes,
        ).toBe('Split in Money Pro: Restaurantes Restaurantes Restaurantes');
    });

    it('adds the split note after a note of its own', async () => {
        const parsed = await parseImportFile(
            new File(
                [
                    'Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción;Nota\n1 feb 2026, 10:00:00;10,00 €;Cuenta Ficticia;;;100,00 €;Compras Salud;Compra inventada;Gasto;Pagado a medias\n',
                ],
                'money-pro.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            { ...defaultMapping('money_pro', parsed, 'es-ES'), notes: 'Nota' },
            { fileName: 'money-pro.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers, {
                rows: parsed.rows,
                categories: [
                    { id: 'c1', name: 'Compras', parent_id: null },
                    { id: 'c2', name: 'Salud', parent_id: null },
                ],
            }),
        );

        expect(normalized.rows[0]).toMatchObject({
            sourceSplit: true,
            categoryPath: [],
            notes: 'Pagado a medias\nSplit in Money Pro: Compras Salud',
        });
    });

    it('cuts a long note of its own first, so the split note always fits', async () => {
        const own = 'Pagado a medias. '.repeat(400);
        const parsed = await parseImportFile(
            new File(
                [
                    `Fecha;Cantidad;Cuenta;Monto recibido;Cuenta de destino;Saldo;Categoría;Descripción;Tipo de transacción;Nota\n1 feb 2026, 10:00:00;10,00 €;Cuenta Ficticia;;;100,00 €;Compras Salud;Compra inventada;Gasto;${own}\n`,
                ],
                'money-pro.csv',
                { type: 'text/csv' },
            ),
            'es-ES',
        );
        const normalized = normalizeRows(
            parsed,
            { ...defaultMapping('money_pro', parsed, 'es-ES'), notes: 'Nota' },
            { fileName: 'money-pro.csv', supportedCurrencies: SUPPORTED },
            moneyProReader(parsed.headers, {
                rows: parsed.rows,
                categories: [
                    { id: 'c1', name: 'Compras', parent_id: null },
                    { id: 'c2', name: 'Salud', parent_id: null },
                ],
            }),
        );
        const notes = normalized.rows[0].notes ?? '';

        expect(own.trim().length).toBeGreaterThan(5000);
        expect(notes).toHaveLength(5000);
        expect(notes.startsWith('Pagado a medias. Pagado')).toBe(true);
        expect(notes.endsWith('\nSplit in Money Pro: Compras Salud')).toBe(
            true,
        );
    });

    it('tells the cells apart by what the file writes alone and what the user has', () => {
        const rows = [
            'Compras',
            'Salud',
            'Supermercado : Alimentación',
            'Familia: Colegio',
            'Suscripciones Online',
            'Suscripciones Online',
        ].map((cell) => ({ Categoría: cell }));
        const vocabulary = categoryVocabulary({ rows }, 'Categoría');
        const multi = (cell: string) => isMultiCategoryCell(cell, vocabulary);

        // Two parents or more: several categories, whatever else.
        expect(multi('Coche: Gasolina Viajes: Playa')).toBe(true);
        expect(multi('A:B:C')).toBe(true);
        // Repeats, and the "Parent : Child" spacing.
        expect(multi('Salud Salud')).toBe(true);
        expect(multi('Supermercado: Alimentación Compras')).toBe(true);
        expect(multi('Compras Supermercado : Alimentación')).toBe(true);
        // One category, with a space in its name or not, alone or a one-off.
        expect(multi('Suscripciones Online')).toBe(false);
        expect(multi('Supermercado : Alimentación')).toBe(false);
        expect(multi('Familia: Colegio')).toBe(false);
        expect(multi('Viajes Familiares')).toBe(false);
        expect(multi('Compras Online')).toBe(false);
        expect(multi('Salario: Nómina')).toBe(false);
        expect(multi('')).toBe(false);
        // A one-off with two categories and an unknown word between them.
        expect(multi('Familia: Libros Papel Compras')).toBe(true);
        // The same cell, repeated, is a category of its own.
        expect(
            isMultiCategoryCell(
                'Familia: Libros Papel Compras',
                categoryVocabulary(
                    {
                        rows: [
                            ...rows,
                            { Categoría: 'Familia: Libros Papel Compras' },
                            { Categoría: 'Familia: Libros Papel Compras' },
                        ],
                    },
                    'Categoría',
                ),
            ),
        ).toBe(false);
    });

    it("knows the user's categories, and never cuts one of them", () => {
        const vocabulary = categoryVocabulary(
            {
                categories: [
                    { id: 'food', name: 'Comida', parent_id: null },
                    { id: 'out', name: 'Restaurantes', parent_id: 'food' },
                    { id: 'tv', name: 'Televisión', parent_id: null },
                    {
                        id: 'tv-out',
                        name: 'Comida Televisión',
                        parent_id: null,
                    },
                ],
            },
            'Categoría',
        );

        expect(isMultiCategoryCell('Televisión Televisión', vocabulary)).toBe(
            true,
        );
        expect(
            isMultiCategoryCell('Comida: Restaurantes Televisión', vocabulary),
        ).toBe(true);
        expect(isMultiCategoryCell('Comida Televisión', vocabulary)).toBe(
            false,
        );
        expect(isMultiCategoryCell('Comida: Restaurantes', vocabulary)).toBe(
            false,
        );
    });

    it("knows a child of the user's by its full path, never by its bare name", () => {
        const vocabulary = categoryVocabulary(
            {
                rows: [
                    { Categoría: 'Suscripciones Online' },
                    { Categoría: 'Suscripciones Online' },
                    { Categoría: 'Compras' },
                ],
                categories: [
                    { id: 'shop', name: 'Compras', parent_id: null },
                    { id: 'web', name: 'Online', parent_id: 'shop' },
                    { id: 'subs', name: 'Suscripciones', parent_id: null },
                ],
            },
            'Categoría',
        );

        expect(vocabulary.known.has('online')).toBe(false);
        expect(vocabulary.known.has('compras: online')).toBe(true);
        expect(isMultiCategoryCell('Suscripciones Online', vocabulary)).toBe(
            false,
        );
        expect(isMultiCategoryCell('Compras: Online Compras', vocabulary)).toBe(
            true,
        );
    });

    it('reads a long cell in pieces no longer than a known category, once', () => {
        const vocabulary = categoryVocabulary(
            {
                rows: [
                    'Compras',
                    'Salud',
                    'Supermercado : Alimentación',
                    'Familia: Colegio',
                ].map((cell) => ({ Categoría: cell })),
            },
            'Categoría',
        );
        const lookups = vi.spyOn(vocabulary.known, 'has');
        const unknown = Array.from({ length: 60 }, (_, i) => `palabra${i}`);
        const mixed = Array.from({ length: 30 }, () => 'Compras Salud');
        const started = performance.now();

        expect(isMultiCategoryCell(unknown.join(' '), vocabulary)).toBe(false);
        expect(isMultiCategoryCell(mixed.join(' '), vocabulary)).toBe(true);
        expect(
            isMultiCategoryCell(
                `Familia: Libros ${unknown.slice(2).join(' ')} Compras`,
                vocabulary,
            ),
        ).toBe(true);
        expect(performance.now() - started).toBeLessThan(250);
        // Pieces of at most two words: about two lookups a word, not one
        // for every pair of words (1,830 for 60 words).
        expect(lookups.mock.calls.length).toBeLessThan(3 * 3 * 60);

        lookups.mockClear();

        expect(isMultiCategoryCell(mixed.join(' '), vocabulary)).toBe(true);
        expect(lookups).not.toHaveBeenCalled();
    });

    it('creates no category from a split cell, and sends its rows marked', async () => {
        const { mapping, normalized } = await readSplits();
        const nodes = detectCategories(
            normalized.rows.filter((row) => row.kind === 'movement'),
        );
        const target = (name: string): TransferTarget => ({
            category_id: null,
            name,
            icon: 'Split',
            color: 'stone',
        });
        const built = buildImport({
            source: 'money_pro',
            fileName: 'money-pro.csv',
            mode: 'add',
            mapping,
            rows: normalized.rows,
            fileAccounts: detectAccounts(normalized.rows),
            accountPlan: {
                'cuenta ficticia': {
                    action: 'create',
                    name: 'Cuenta Ficticia',
                    type: 'checking',
                    currencyCode: 'EUR',
                    bank: null,
                    newBankName: null,
                    targetAccountId: null,
                    mergeIntoKey: null,
                    archive: false,
                },
            },
            inactiveAccountKeys: new Set(),
            contextAccounts: [],
            nodes,
            categoryPlan: resolveCategoryPlan(
                nodes,
                {},
                { categories: [], defaultNames: [] },
            ),
            categories: [],
            transfers: {
                own: { categoryId: null, target: target('Own transfers') },
                ignored: { categoryId: null, target: target('Ignored') },
            },
        });

        expect(
            built.payload.categories.map((entry) => entry.name).sort(),
        ).toEqual(
            [
                'Alimentación',
                'Compras',
                'Colegio',
                'Empresa',
                'Empresa',
                'Familia',
                'Restaurantes',
                'Salario',
                'Salud',
                'Supermercado',
                'Suscripciones Online',
                'Nómina',
            ].sort(),
        );

        const split = built.transactions.filter((row) => row.source_split);

        expect(split).toHaveLength(6);
        expect(split.every((row) => row.category_key === null)).toBe(true);
        expect(split[0]).toMatchObject({
            description: 'Compra grande inventada',
            amount: -1000,
            notes: 'Split in Money Pro: Familia: Ropa Supermercado : Hogar Supermercado : Alimentación',
            source_split: true,
        });
        expect(
            built.transactions.filter((row) => !row.source_split),
        ).not.toContainEqual(expect.objectContaining({ category_key: null }));
        expect(
            built.transactions.filter((row) => !row.source_split),
        ).not.toContainEqual(
            expect.objectContaining({ source_split: expect.anything() }),
        );
    });
});
