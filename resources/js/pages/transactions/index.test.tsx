import { type TransactionTable } from '@/components/transactions/transaction-table';
import { render } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Transactions from './index';

type TableProps = ComponentProps<typeof TransactionTable>;

const { table, refreshSummary } = vi.hoisted(() => ({
    table: { props: null as TableProps | null },
    refreshSummary: vi.fn(),
}));

vi.mock('@/components/transactions/transaction-table', () => ({
    TransactionTable: (props: TableProps) => {
        table.props = props;

        return null;
    },
}));
vi.mock('@/hooks/use-server-transaction-source', () => ({
    useServerTransactionSource: () => ({
        rows: [],
        filters: {
            dateFrom: null,
            dateTo: null,
            amountMin: null,
            amountMax: null,
            categoryIds: [],
            categoryTypes: [],
            accountIds: [],
            labelIds: [],
            creditorName: '',
            debtorName: '',
            searchText: '',
            aiCategorizedOnly: false,
        },
        reload: vi.fn(),
        refreshSummary,
    }),
}));
vi.mock('@/hooks/use-ai-categorization-consent', () => ({
    useAiCategorizationConsent: () => ({ showBanner: false }),
}));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@inertiajs/react', () => ({ Head: () => null }));

describe('Transactions page', () => {
    beforeEach(() => {
        table.props = null;
    });

    it('asks for the filter summary again when the table reports a local edit', () => {
        render(
            <Transactions
                {...({
                    transactions: { data: [] },
                    dayTotals: {},
                    appliedFilters: {},
                    categories: [],
                    accounts: [],
                    banks: [],
                    labels: [],
                    automationRules: [],
                    hasAiConsent: false,
                    aiConsentPromptDismissed: false,
                    lastVisitAt: null,
                } as unknown as ComponentProps<typeof Transactions>)}
            />,
        );

        table.props?.onTransactionsChanged?.();

        expect(refreshSummary).toHaveBeenCalledOnce();
    });
});
