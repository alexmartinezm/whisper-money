import { DateFormat } from '@/types/import';
import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepMapping } from './import-step-mapping';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

function renderStep(tracksBalance: boolean) {
    render(
        <ImportStepMapping
            columnOptions={[{ value: 'Saldo', label: 'Saldo', examples: [] }]}
            columnMapping={{
                transaction_date: null,
                description: null,
                amount: null,
                balance: null,
                creditor_name: null,
                debtor_name: null,
            }}
            dateFormat={DateFormat.YearMonthDay}
            dateFormatDetected
            parsedData={[]}
            currencyCode="EUR"
            tracksBalance={tracksBalance}
            calculateBalances={false}
            referenceBalance={null}
            referenceBalancePrefilled={false}
            calculateBalancesAvailable
            onMappingChange={vi.fn()}
            onDateFormatChange={vi.fn()}
            onCalculateBalancesChange={vi.fn()}
            onReferenceBalanceChange={vi.fn()}
            onLatestDateChange={vi.fn()}
            onNext={vi.fn()}
            onBack={vi.fn()}
        />,
    );
}

describe('ImportStepMapping', () => {
    it('offers the balance column and the calculate toggle for an account with a balance', () => {
        renderStep(true);

        expect(document.getElementById('balance-column')).not.toBeNull();
        expect(document.getElementById('calculate-balances')).not.toBeNull();
    });

    it('hides every balance control for a credit card', () => {
        renderStep(false);

        expect(document.getElementById('balance-column')).toBeNull();
        expect(document.getElementById('calculate-balances')).toBeNull();
        expect(document.getElementById('creditor-column')).not.toBeNull();
    });
});
