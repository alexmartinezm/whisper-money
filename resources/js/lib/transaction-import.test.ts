import { DateFormat } from '@/types/import';
import { describe, expect, it } from 'vitest';
import {
    isSupportedImportFile,
    parseImportFile,
    unsupportedFileReason,
} from './transaction-import';

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
});
