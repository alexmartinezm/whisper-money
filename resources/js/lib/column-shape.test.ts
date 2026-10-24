import { describe, expect, it } from 'vitest';
import {
    columnShape,
    describeColumns,
    isAllPositive,
    isHeaderRow,
    valueKind,
} from './column-shape';

describe('columnShape', () => {
    it.each([
        [['15/03/2025', '02/04/2025'], 'date (dd/mm/yyyy)'],
        [['03/15/2025', '04/02/2025'], 'date (mm/dd/yyyy)'],
        [['01.02.2025', '03.04.2025'], 'date (dd.mm or mm.dd.yyyy)'],
        [['2025-03-15', '2025-04-02'], 'date (yyyy-mm-dd)'],
        [
            ['2025-03-15 10:22:01', '2025-04-02 08:00:00'],
            'date (yyyy-mm-dd with time)',
        ],
        [['15-03-25', '20-04-25'], 'date (dd-mm-yy)'],
        [['20250315', '20250402'], 'date (yyyymmdd)'],
        [['15 Mar 2025', '2 Apr 2025'], 'date (with month name)'],
        [['45731', '45750'], 'date (spreadsheet serial number)'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it.each([
        [
            ['-23,45', '1.850,00', '-9,99'],
            'number, 2 decimals with ",", mixed signs',
        ],
        [['23.45', '1,850.00'], 'number, 2 decimals with ".", all positive'],
        [['-23.45', '-9.99'], 'number, 2 decimals with ".", all negative'],
        [
            ['€ -23,45', '1.850,00 €'],
            'number, 2 decimals with ",", with currency symbol, mixed signs',
        ],
        [['(23.45)', '12.00'], 'number, 2 decimals with ".", mixed signs'],
        [['12', '-3'], 'integer, mixed signs'],
        [['100231', '845512', '312908'], 'integer id-like'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it.each([
        [['10:22:01', '18:03'], 'time'],
        [['EUR', 'USD'], 'three-letter uppercase code'],
        [['ES7621000418401234567891', 'NL91 ABNA 0417 1643 00'], 'IBAN'],
        [[], 'empty'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it('describes text by length and how much it repeats', () => {
        expect(columnShape(['Af', 'Bij', 'Af', 'Af'])).toBe(
            'text, avg 2 chars, 2 distinct values',
        );
        expect(columnShape(['Pago', 'Pago'])).toBe(
            'text, avg 4 chars, same value repeated',
        );
        expect(columnShape(['MERCADONA', 'NETFLIX.COM', 'AMAZON EU'])).toBe(
            'text, avg 10 chars, mostly distinct values',
        );
    });
});

describe('describeColumns', () => {
    const rows = [
        {
            Buchungstag: '01.03.2025',
            Verwendungszweck: 'REWE Markt Berlin',
            Betrag: '-12,40',
        },
        {
            Buchungstag: '15.03.2025',
            Verwendungszweck: 'Gehalt März',
            Betrag: '2.400,00',
        },
        { Buchungstag: '20.03.2025', Verwendungszweck: '', Betrag: '-5,00' },
    ];

    it('describes every column by header and shape, in file order', () => {
        expect(
            describeColumns(
                ['Buchungstag', 'Verwendungszweck', 'Betrag'],
                rows,
                true,
            ),
        ).toEqual([
            { header: 'Buchungstag', shape: 'date (dd.mm.yyyy)' },
            {
                header: 'Verwendungszweck',
                shape: 'text, avg 14 chars, mostly distinct values',
            },
            {
                header: 'Betrag',
                shape: 'number, 2 decimals with ",", mixed signs',
            },
        ]);
    });

    it('never carries a cell value', () => {
        const described = JSON.stringify(
            describeColumns(
                ['Buchungstag', 'Verwendungszweck', 'Betrag'],
                rows,
                true,
            ),
        );

        for (const row of rows) {
            for (const value of Object.values(row).filter(Boolean)) {
                expect(described).not.toContain(value);
            }
        }
    });

    it('works the shape out from a bounded sample of a long file', () => {
        const long = Array.from({ length: 5000 }, (_, index) => ({
            Amount: String(index % 2 === 0 ? -index : index),
        }));

        expect(describeColumns(['Amount'], long, true)[0].shape).toBe(
            'integer, mixed signs',
        );
    });
});

describe('valueKind', () => {
    it.each([
        ['01/12/2025', 'date'],
        ['2025-12-01', 'date'],
        ['01/12', 'date'],
        ['1 dic 2025', 'date'],
        ['1 de diciembre de 2025', 'date'],
        ['1 dic 2025, 0:00:00', 'date'],
        ['Dec 1, 2025', 'date'],
        ['-45,20', 'number'],
        ['1.234,56 €', 'number'],
        ['EUR 12.00', 'number'],
        ['(12.40)', 'number'],
        ['20251201', 'number'],
        ['EUR', 'code'],
        ['ES91 2100 0418 4502 0005 1332', 'iban'],
        ['10:30', 'time'],
    ])('reads %s as a value (%s)', (text, kind) => {
        expect(valueKind(text)).toBe(kind);
    });

    it.each([
        'Concepto',
        'Fecha valor',
        'Importe (EUR)',
        'Saldo 2024',
        'MERCADONA VALENCIA',
        'Buchungstag',
        'Eur',
        '',
    ])('reads %s as a word', (text) => {
        expect(valueKind(text)).toBeNull();
    });
});

describe('isHeaderRow', () => {
    it('takes a row of names over the values for a header row', () => {
        const columns = [
            ['Fecha', '01/12/2025', '02/12/2025'],
            ['Concepto', 'MERCADONA VALENCIA', 'NETFLIX.COM'],
            ['Importe', '-45,20', '-12,99'],
        ];

        expect(isHeaderRow(columns, 0)).toBe(true);
    });

    it('turns down a first movement taken for headers', () => {
        const columns = [
            ['01/12/2025', '02/12/2025', '03/12/2025'],
            ['MERCADONA VALENCIA', 'NETFLIX.COM', 'BAR PACO'],
            ['-45,20', '-12,99', '-3,50'],
        ];

        expect(isHeaderRow(columns, 0)).toBe(false);
    });

    it('turns down a spreadsheet row of numbers over numbers', () => {
        // A date and an amount in .xlsx arrive as numbers, which the reader
        // swaps for column letters: only the raw cells tell.
        const columns = [
            [45992, 45993],
            ['MERCADONA VALENCIA', 'NETFLIX.COM'],
            [-45.2, -12.99],
        ];

        expect(isHeaderRow(columns, 0)).toBe(false);
    });

    it('reads the row the reader picked, past a title above it', () => {
        const columns = [
            ['Extracto', 'Fecha', '01/12/2025'],
            [undefined, 'Importe', '-45,20'],
        ];

        expect(isHeaderRow(columns, 1)).toBe(true);
    });

    it('keeps a header row whose currency header sits over amounts', () => {
        const columns = [
            ['Fecha', '01/12/2025'],
            ['EUR', '-45,20'],
        ];

        expect(isHeaderRow(columns, 0)).toBe(true);
    });
});

describe('the headers sent', () => {
    const headerRow = [
        { Fecha: '01/12/2025', Concepto: 'MERCADONA', Importe: '-45,20' },
        { Fecha: '02/12/2025', Concepto: 'NETFLIX.COM', Importe: '-12,99' },
    ];

    it('are the header row of a file that has one', () => {
        expect(
            describeColumns(
                ['Fecha', 'Concepto', 'Importe'],
                headerRow,
                true,
            ).map((column) => column.header),
        ).toEqual(['Fecha', 'Concepto', 'Importe']);
    });

    it('are none at all for a file without a header row', () => {
        const headers = ['01/12/2025', 'MERCADONA VALENCIA', '-45,20'];
        const rows = [
            {
                '01/12/2025': '02/12/2025',
                'MERCADONA VALENCIA': 'NETFLIX.COM',
                '-45,20': '-12,99',
            },
        ];
        const described = describeColumns(headers, rows, false);

        expect(described).toEqual([
            { header: null, shape: 'date (dd/mm or mm/dd/yyyy)' },
            {
                header: null,
                shape: 'text, avg 11 chars, same value repeated',
            },
            {
                header: null,
                shape: 'number, 2 decimals with ",", all negative',
            },
        ]);
        expect(JSON.stringify(described)).not.toContain('MERCADONA');
    });

    it('leave out a header that reads as a value, keeping the words', () => {
        const rows = [
            {
                '01/12/2025': '02/12/2025',
                'Importe (EUR)': '-12,99',
                EUR: 'EUR',
                'ES91 2100 0418 4502 0005 1332': 'x',
                '-45,20': 'y',
                'Fecha valor': '03/12/2025',
            },
        ];

        expect(
            describeColumns(Object.keys(rows[0]), rows, true).map(
                (column) => column.header,
            ),
        ).toEqual([null, 'Importe (EUR)', null, null, null, 'Fecha valor']);
    });
});

describe('the sign of a long column', () => {
    it('is read over every value, not only the sample', () => {
        const amounts = Array.from({ length: 1000 }, (_, index) => ({
            Amount: index === 501 ? '-5.00' : '12.00',
        }));
        const [column] = describeColumns(['Amount'], amounts, true);

        expect(column.shape).toBe('number, 2 decimals with ".", mixed signs');
        expect(isAllPositive(column.shape)).toBe(false);
    });

    it('tells an all-positive amount column apart', () => {
        expect(isAllPositive('number, 2 decimals with ",", all positive')).toBe(
            true,
        );
        expect(isAllPositive('integer, all positive')).toBe(true);
        expect(isAllPositive('number, 2 decimals with ",", mixed signs')).toBe(
            false,
        );
        expect(
            isAllPositive('text, avg 12 chars, mostly distinct values'),
        ).toBe(false);
    });
});
