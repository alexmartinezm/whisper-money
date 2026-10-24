import { parseFile } from '@/lib/file-parser';
import type { Account } from '@/types/account';
import type { BalanceColumnMapping } from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportBalancesDrawer } from './import-balances-drawer';

const {
    fetchColumnSuggestions,
    loadBalanceImportConfig,
    saveBalanceImportConfig,
    mappingProps,
} = vi.hoisted(() => ({
    fetchColumnSuggestions: vi.fn(),
    loadBalanceImportConfig: vi.fn(),
    saveBalanceImportConfig: vi.fn(),
    mappingProps: vi.fn(),
}));

/** The shared `aiImportMappingEnabled` prop: on here, off in this fork by default. */
const aiMapping = vi.hoisted(() => ({ enabled: true }));

const headers = ['Datum', 'Depotwert', 'Einstand'];
const rows = [
    { Datum: '31.01.2025', Depotwert: '10.200,00', Einstand: '9.000,00' },
    { Datum: '28.02.2025', Depotwert: '10.650,00', Einstand: '9.500,00' },
];
const file = new File(['x'], 'depot.csv');

vi.mock('@/lib/file-parser', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/file-parser')>()),
    parseFile: vi.fn(async () => ({
        headers,
        data: rows,
        rowNumbers: [2, 3],
        columns: headers.map((header) => [
            header,
            ...rows.map((row) => row[header as keyof (typeof rows)[0]]),
        ]),
        headerRowIndex: 0,
    })),
}));

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadBalanceImportConfig,
    saveBalanceImportConfig,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));
vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'token' }));

vi.mock('@/components/accounts/synced-balance-notice', () => ({
    SyncedBalanceNotice: () => null,
}));

vi.mock('./import-balances/import-balance-step-upload', () => ({
    ImportBalanceStepUpload: ({
        onFileSelect,
        onNext,
    }: {
        onFileSelect: (file: File) => Promise<void>;
        onNext: () => void;
    }) => (
        <button
            type="button"
            onClick={async () => {
                await onFileSelect(file);
                onNext();
            }}
        >
            pick file
        </button>
    ),
}));

vi.mock('./import-balances/import-balance-step-mapping', () => ({
    ImportBalanceStepMapping: (props: {
        columnMapping: BalanceColumnMapping;
        aiDetection: ReactNode;
        onNext: () => void;
    }) => {
        mappingProps(props);

        return (
            <>
                {props.aiDetection}
                <button type="button" onClick={props.onNext}>
                    leave mapping
                </button>
            </>
        );
    },
}));

vi.mock('./import-balances/import-balance-step-preview', () => ({
    ImportBalanceStepPreview: ({ onConfirm }: { onConfirm: () => void }) => (
        <button type="button" onClick={onConfirm}>
            confirm import
        </button>
    ),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'de', aiImportMappingEnabled: aiMapping.enabled },
    }),
}));

const account = {
    id: 'account-1',
    name: 'Depot',
    type: 'investment',
    currency_code: 'EUR',
    banking_connection_id: null,
} as Account;

function shownMapping(): BalanceColumnMapping {
    return mappingProps.mock.calls.at(-1)?.[0].columnMapping;
}

async function reachMapping() {
    render(
        <ImportBalancesDrawer
            open
            onOpenChange={vi.fn()}
            account={account}
            accountId={account.id}
        />,
    );

    fireEvent.click(await screen.findByText('pick file'));
    await screen.findByText('leave mapping');
}

describe('ImportBalancesDrawer with AI column mapping', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        aiMapping.enabled = true;
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({ data: { id: 'balance' } }),
        });
        loadBalanceImportConfig.mockResolvedValue(null);
        fetchColumnSuggestions.mockResolvedValue({
            cached: true,
            fields: {
                balance_date: { column: 'Datum', index: 0, confidence: 0.9 },
                balance: { column: 'Depotwert', index: 1, confidence: 0.9 },
                invested_amount: {
                    column: 'Einstand',
                    index: 2,
                    confidence: 0.9,
                },
            },
        });
    });

    afterEach(() => vi.unstubAllGlobals());

    it('asks the AI for the balance columns when no stored layout fits', async () => {
        await reachMapping();

        await screen.findByText('Suggested by AI');

        expect(fetchColumnSuggestions).toHaveBeenCalledWith(
            'balance',
            expect.arrayContaining([
                { header: 'Datum', shape: 'date (dd.mm.yyyy)' },
            ]),
            'account-1',
        );
        expect(shownMapping()).toEqual({
            balance_date: 'Datum',
            balance: 'Depotwert',
            invested_amount: 'Einstand',
        });
    });

    it('sends no header of a file without a header row, and maps by position', async () => {
        // The reader took the first balance for the headers.
        const firstRow = ['31.01.2025', '10.200,00', '9.000,00'];
        const next = { '31.01.2025': '28.02.2025', '10.200,00': '10.650,00' };
        vi.mocked(parseFile).mockResolvedValueOnce({
            headers: firstRow,
            data: [{ ...next, '9.000,00': '9.500,00' }],
            rowNumbers: [2],
            columns: [
                ['31.01.2025', '28.02.2025'],
                ['10.200,00', '10.650,00'],
                ['9.000,00', '9.500,00'],
            ],
            headerRowIndex: 0,
        });
        fetchColumnSuggestions.mockResolvedValue({
            cached: false,
            fields: {
                balance_date: { column: null, index: 0, confidence: 0.9 },
                balance: { column: null, index: 1, confidence: 0.9 },
                invested_amount: { column: null, index: 2, confidence: 0.9 },
            },
        });

        await reachMapping();
        await screen.findByText('Suggested by AI');

        const [, columns] = fetchColumnSuggestions.mock.calls[0];
        expect(
            columns.map((column: { header: string | null }) => column.header),
        ).toEqual([null, null, null]);
        expect(shownMapping()).toEqual({
            balance_date: '31.01.2025',
            balance: '10.200,00',
            invested_amount: '9.000,00',
        });
    });

    it('does not ask the AI about a file of one row and no header row', async () => {
        // The reader took the only balance for the headers: no row is left.
        const onlyRow = ['31.01.2025', '10.200,00', '9.000,00'];
        vi.mocked(parseFile).mockResolvedValueOnce({
            headers: onlyRow,
            data: [],
            rowNumbers: [],
            columns: onlyRow.map((cell) => [cell]),
            headerRowIndex: 0,
        });

        await reachMapping();
        await waitFor(() => expect(loadBalanceImportConfig).toHaveBeenCalled());
        await act(async () => {});

        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Detect columns with AI' }),
        ).toBeInTheDocument();
    });

    it('saves the layout only once balances made it in', async () => {
        await reachMapping();
        await screen.findByText('Suggested by AI');

        fireEvent.click(screen.getByText('leave mapping'));
        await screen.findByText('confirm import');

        expect(saveBalanceImportConfig).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('confirm import'));

        await waitFor(() =>
            expect(saveBalanceImportConfig).toHaveBeenCalledWith('account-1', {
                columnMapping: {
                    balance_date: 'Datum',
                    balance: 'Depotwert',
                    invested_amount: 'Einstand',
                },
                dateFormat: DateFormat.DayMonthYear,
                aiSuggested: true,
            }),
        );
    });

    it('leaves no layout behind when every balance failed', async () => {
        fetchMock.mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'No' }),
        });

        await reachMapping();
        fireEvent.click(screen.getByText('leave mapping'));
        fireEvent.click(await screen.findByText('confirm import'));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(saveBalanceImportConfig).not.toHaveBeenCalled();
    });

    it('keeps a stored layout that fits without asking the AI', async () => {
        loadBalanceImportConfig.mockResolvedValue({
            columnMapping: {
                balance_date: 'Datum',
                balance: 'Depotwert',
                invested_amount: null,
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        await reachMapping();

        expect(
            await screen.findByRole('button', {
                name: 'Detect columns with AI',
            }),
        ).toBeInTheDocument();
        await waitFor(() => expect(shownMapping().invested_amount).toBeNull());
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
    });
});

describe('ImportBalancesDrawer with AI column mapping off', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        aiMapping.enabled = false;
        loadBalanceImportConfig.mockResolvedValue(null);
    });

    afterEach(() => {
        aiMapping.enabled = true;
    });

    it('shows no AI control and never asks the AI', async () => {
        await reachMapping();

        expect(
            screen.queryByRole('button', { name: 'Detect columns with AI' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Suggested by AI')).not.toBeInTheDocument();
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
    });

    // The drawer is portalled out of the page, so only a class on its own
    // content keeps PostHog autocapture off the balances inside.
    it('keeps PostHog autocapture off the whole drawer', async () => {
        await reachMapping();

        expect(screen.getByRole('dialog')).toHaveClass('ph-no-capture');
    });
});
