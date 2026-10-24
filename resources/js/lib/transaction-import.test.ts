import { loadImportConfig } from '@/lib/import-config-storage';
import { DateFormat } from '@/types/import';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyStoredImportConfig,
    isSupportedImportFile,
    parseImportFile,
    unsupportedFileReason,
    type ParsedImportFile,
} from './transaction-import';

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(),
}));

function csvFile(lines: string[], name = 'movements.csv'): File {
    return new File([lines.join('\n')], name, { type: 'text/csv' });
}

describe('isSupportedImportFile', () => {
    it('accepts the formats the parser reads, whatever the case', () => {
        expect(isSupportedImportFile(new File([''], 'a.csv'))).toBe(true);
        expect(isSupportedImportFile(new File([''], 'a.XLSX'))).toBe(true);
        expect(isSupportedImportFile(new File([''], 'a.xls'))).toBe(true);
    });

    it('turns away anything else, and a file without an extension', () => {
        expect(isSupportedImportFile(new File([''], 'a.pdf'))).toBe(false);
        expect(isSupportedImportFile(new File([''], 'a.numbers'))).toBe(false);
        expect(isSupportedImportFile(new File([''], 'statement'))).toBe(false);
        expect(isSupportedImportFile(null)).toBe(false);
    });
});

describe('unsupportedFileReason', () => {
    it('names the format the file actually has', () => {
        expect(unsupportedFileReason('statement.pdf')).toBe(
            'PDF is not supported',
        );
    });

    it('says so when the file has no format at all', () => {
        expect(unsupportedFileReason('statement')).toBe(
            'That file has no format we recognise',
        );
    });
});

describe('parseImportFile', () => {
    it('reads the rows and guesses the mapping and date format', async () => {
        const parsed = await parseImportFile(
            csvFile([
                'Fecha,Concepto,Importe,Moneda',
                '2026-06-02,Internet,-10.50,EUR',
                '',
                '2026-06-03,Groceries,-25,USD',
            ]),
        );

        expect(parsed.headers).toEqual([
            'Fecha',
            'Concepto',
            'Importe',
            'Moneda',
        ]);
        expect(parsed.rows).toHaveLength(2);
        expect(parsed.rowNumbers).toEqual([2, 4]);
        expect(parsed.mapping).toMatchObject({
            transaction_date: 'Fecha',
            description: 'Concepto',
            amount: 'Importe',
            currency: 'Moneda',
        });
        expect(parsed.dateFormat).toBe(DateFormat.YearMonthDay);
        expect(parsed.dateFormatDetected).toBe(true);
        expect(parsed.dateFormatAmbiguous).toBe(false);
        expect(parsed.columnOptions.map((option) => option.value)).toEqual(
            parsed.headers,
        );
    });

    it('flags dates that read more than one way, preferring the locale', async () => {
        const parsed = await parseImportFile(
            csvFile(['Date,Description,Amount', '02/06/2026,Internet,-10']),
            'en-GB',
        );

        expect(parsed.dateFormat).toBe(DateFormat.DayMonthYear);
        expect(parsed.dateFormatAmbiguous).toBe(true);
        expect(parsed.dateFormatDetected).toBe(false);
    });

    it('maps a type column and pre-ticks the values that mean an expense', async () => {
        const parsed = await parseImportFile(
            csvFile([
                'Fecha,Concepto,Importe,Tipo',
                '2026-06-02,Internet,10.50,Gasto',
                '2026-06-03,Salary,1200,Ingreso',
            ]),
        );

        expect(parsed.mapping.transaction_type).toBe('Tipo');
        expect(parsed.mapping.expense_types).toEqual(['Gasto']);
    });
});

const ACCOUNT_ID = '00000000-0000-0000-0000-000000000001';

function parsedFile(): ParsedImportFile {
    return {
        file: new File([''], 'money-pro.csv'),
        rows: [],
        rowNumbers: [],
        headers: ['Fecha', 'Descripción', 'Cantidad', 'Tipo'],
        columnOptions: [],
        mapping: {
            transaction_date: 'Fecha',
            description: 'Descripción',
            amount: 'Cantidad',
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: 'Tipo',
            expense_types: ['Gasto'],
        },
        dateFormat: DateFormat.DayMonthYear,
        dateFormatDetected: true,
        dateFormatAmbiguous: false,
    };
}

describe('applyStoredImportConfig with a type column', () => {
    beforeEach(() => {
        vi.mocked(loadImportConfig).mockReset();
    });

    it('applies the stored expense values, which are not columns of the file', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                expense_types: ['Gasto', 'Ajuste de saldo'],
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('Tipo');
        expect(applied?.mapping.expense_types).toEqual([
            'Gasto',
            'Ajuste de saldo',
        ]);
    });

    it('keeps the detected type column under a layout saved before it existed', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                transaction_date: 'Fecha',
                description: 'Descripción',
                amount: 'Cantidad',
                currency: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('Tipo');
        expect(applied?.mapping.expense_types).toEqual(['Gasto']);
    });

    it('ignores a stored layout whose type column this file lacks', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                transaction_type: 'Movement type',
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        expect(
            await applyStoredImportConfig(parsedFile(), ACCOUNT_ID),
        ).toBeNull();
    });

    it('applies a stored layout whose type column was set back to none', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: {
                ...parsedFile().mapping,
                creditor_name: '',
                transaction_type: '',
                expense_types: [],
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        const applied = await applyStoredImportConfig(parsedFile(), ACCOUNT_ID);

        expect(applied?.mapping.transaction_type).toBe('');
        expect(applied?.mapping.expense_types).toEqual([]);
    });

    it('keeps the format question open when the dates are ambiguous', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue({
            columnMapping: parsedFile().mapping,
            dateFormat: DateFormat.MonthDayYear,
        });

        const applied = await applyStoredImportConfig(
            { ...parsedFile(), dateFormatAmbiguous: true },
            ACCOUNT_ID,
        );

        expect(applied?.dateFormat).toBe(DateFormat.MonthDayYear);
        expect(applied?.dateFormatDetected).toBe(false);
    });

    it('applies nothing when the account has no stored layout', async () => {
        vi.mocked(loadImportConfig).mockResolvedValue(null);

        expect(
            await applyStoredImportConfig(parsedFile(), ACCOUNT_ID),
        ).toBeNull();
    });
});
