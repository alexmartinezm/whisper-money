import { captureEvent } from '@/lib/posthog';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { transactionSyncService } from '@/services/transaction-sync';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import { todayDateString } from '@/utils/date';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type React from 'react';
import { useLayoutEffect, useRef } from 'react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EditTransactionDialog,
    haveSplitLinesChanged,
} from './edit-transaction-dialog';

const inertiaProps = vi.hoisted(() => ({
    features: { transactionSplitting: true },
    auth: { user: { currency_code: 'EUR' } },
    currencies: {
        profile: [{ code: 'EUR', name: 'Euro' }],
        accounts: [
            { code: 'EUR', name: 'Euro' },
            { code: 'USD', name: 'US Dollar' },
        ],
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: { delete: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: inertiaProps }),
}));

vi.mock('@/components/shared/label-combobox', () => ({
    LabelCombobox: () => <div />,
}));

vi.mock('@/components/transactions/category-select', () => ({
    // Stands in for the combobox: it reports what it holds and offers every
    // category as a button, so a pick is one click.
    CategorySelect: ({
        value,
        onValueChange,
        categories,
        'data-testid': dataTestId,
    }: {
        value: string;
        onValueChange: (value: string) => void;
        categories: Category[];
        'data-testid'?: string;
    }) => (
        <div data-testid={dataTestId} data-value={value}>
            {categories.map((category) => (
                <button
                    key={category.id}
                    type="button"
                    onClick={() => onValueChange(category.id)}
                >
                    {category.name}
                </button>
            ))}
        </div>
    ),
}));

vi.mock('@/contexts/sync-context', () => ({
    useSyncContext: () => ({ sync: vi.fn() }),
}));

const localeState = vi.hoisted(() => ({ locale: 'en-US' }));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => localeState.locale,
}));

vi.mock('@/lib/key-storage', () => ({
    getStoredKey: () => null,
}));

vi.mock('@/lib/crypto', () => ({
    decrypt: vi.fn(),
    importKey: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/rule-engine', () => ({
    evaluateRulesForNewTransaction: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: {
        create: vi.fn(),
        update: vi.fn(async () => ({})),
        getById: vi.fn(async () => null),
        delete: vi.fn(),
    },
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock('@/components/ui/select', () => ({
    // The dialog renders more than one Select; a named one gets a testid of its
    // own so the account's stays unambiguous.
    Select: ({
        name,
        value,
        onValueChange,
        children,
    }: {
        name?: string;
        value?: string;
        onValueChange?: (value: string) => void;
        children: React.ReactNode;
    }) => (
        <div
            data-testid={name ? `${name}-value` : 'account-value'}
            data-value={value ?? ''}
            onClick={(event) => {
                const next = (event.target as HTMLElement).dataset.selectValue;
                if (next) {
                    onValueChange?.(next);
                }
            }}
        >
            {children}
        </div>
    ),
    SelectTrigger: ({ children, ...props }: React.ComponentProps<'div'>) => (
        <div {...props}>{children}</div>
    ),
    SelectContent: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectItem: ({
        value,
        children,
    }: {
        value: string;
        children: React.ReactNode;
    }) => <div data-select-value={value}>{children}</div>,
    SelectValue: ({
        placeholder,
        children,
    }: {
        placeholder?: string;
        children?: React.ReactNode;
    }) => <span>{children ?? placeholder}</span>,
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({
        children,
        open,
    }: {
        children: React.ReactNode;
        open: boolean;
    }) => (open ? <div>{children}</div> : null),
    DialogContent: ({
        children,
        onOpenAutoFocus,
        ...props
    }: React.HTMLAttributes<HTMLDivElement> & {
        children: React.ReactNode;
        onOpenAutoFocus?: (event: Event) => void;
    }) => {
        const contentRef = useRef<HTMLDivElement>(null);

        useLayoutEffect(() => {
            const content = contentRef.current;
            if (!content || !onOpenAutoFocus) return;

            // Radix dispatches this cancellable event while mounting content.
            const event = new Event('dialog-open-auto-focus', {
                cancelable: true,
            });
            Object.defineProperty(event, 'currentTarget', {
                value: content,
            });
            onOpenAutoFocus(event);
        }, [onOpenAutoFocus]);

        return (
            <div ref={contentRef} data-testid="dialog-content" {...props}>
                {children}
            </div>
        );
    },
    DialogDescription: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogFooter: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogHeader: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogTitle: ({ children }: { children: React.ReactNode }) => (
        <h2>{children}</h2>
    ),
}));

describe('split line dirty checking', () => {
    const persisted = [
        {
            id: 'split-1',
            transaction_id: 'tx-1',
            category_id: 'category-1',
            category: { id: 'category-1', name: 'Food' },
            amount: -400,
            position: 0,
        },
        {
            id: 'split-2',
            transaction_id: 'tx-1',
            category_id: 'category-2',
            category: { id: 'category-2', name: 'Travel' },
            amount: -600,
            position: 1,
        },
    ];

    it('ignores persisted split metadata when lines are unchanged', () => {
        expect(
            haveSplitLinesChanged(persisted, [
                { category_id: 'category-1', amount: -400, position: 0 },
                { category_id: 'category-2', amount: -600, position: 1 },
            ]),
        ).toBe(false);
    });

    it('detects changed split amounts', () => {
        expect(
            haveSplitLinesChanged(persisted, [
                { category_id: 'category-1', amount: -250, position: 0 },
                { category_id: 'category-2', amount: -750, position: 1 },
            ]),
        ).toBe(true);
    });

    it('detects reordered split lines', () => {
        expect(
            haveSplitLinesChanged(persisted, [
                { category_id: 'category-2', amount: -600, position: 0 },
                { category_id: 'category-1', amount: -400, position: 1 },
            ]),
        ).toBe(true);
    });
});

describe('EditTransactionDialog', () => {
    beforeEach(() => {
        inertiaProps.features.transactionSplitting = true;
        localeState.locale = 'en-US';
        vi.clearAllMocks();
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        Object.defineProperty(globalThis, 'localStorage', {
            value: {
                getItem: vi.fn(() => null),
                setItem: vi.fn(),
            },
            configurable: true,
        });
    });

    it('shows counterparty names as read-only fields', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-1',
                    user_id: 'user-1',
                    account_id: 'account-1',
                    category_id: null,
                    description: 'Card payment',
                    description_iv: null,
                    transaction_date: '2026-05-27',
                    amount: -1200,
                    currency_code: 'EUR',
                    notes: null,
                    notes_iv: null,
                    creditor_name: 'Amazon EU',
                    debtor_name: 'Victor Falcon',
                    source: 'imported',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: 'Card payment',
                    decryptedNotes: null,
                    label_ids: [],
                }}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // The redesign reads these out as rows in a details card rather than
        // as greyed-out inputs, so they are text now, not form values.
        expect(screen.getByText('Creditor')).toBeInTheDocument();
        expect(screen.getByText('Amazon EU')).toBeInTheDocument();
        expect(screen.getByText('Debtor')).toBeInTheDocument();
        expect(screen.getByText('Victor Falcon')).toBeInTheDocument();
        expect(
            screen.getByText('These details cannot be edited.'),
        ).toBeInTheDocument();
        expect(screen.queryByDisplayValue('Amazon EU')).not.toBeInTheDocument();
    });

    const checkingAccount = {
        id: 'account-1',
        name: 'Checking',
        name_iv: null,
        encrypted: false,
        bank: null,
        type: 'checking' as const,
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    };

    function renderCreateDialog(
        props: Partial<React.ComponentProps<typeof EditTransactionDialog>> = {},
    ) {
        return render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
                {...props}
            />,
        );
    }

    function fillDescriptionAndAmount(description = 'Dinner', amount = '25') {
        fireEvent.change(
            screen.getByPlaceholderText('Transaction description'),
            { target: { value: description } },
        );
        const amountInput = screen.getByPlaceholderText('25.00');
        fireEvent.change(amountInput, { target: { value: amount } });
        fireEvent.blur(amountInput);
    }

    async function fillAndSubmit(testId: string) {
        fillDescriptionAndAmount();
        fireEvent.click(screen.getByTestId(testId));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalled();
        });
    }

    it('falls back to the first account when nothing else names one', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            'account-1',
        );
    });

    it('leaves the account empty when there is none to pick', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            '',
        );
    });

    it('auto-selects the account matching initialAccountId (account page)', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            'account-1',
        );
    });

    it('leaves the account empty on the page of an account that takes no transactions', async () => {
        vi.mocked(localStorage.getItem).mockImplementation((key: string) =>
            key === 'whisper_money_last_transaction_account'
                ? 'account-1'
                : null,
        );

        renderCreateDialog({
            accounts: [
                checkingAccount,
                {
                    ...checkingAccount,
                    id: 'account-archived',
                    archived_at: '2026-01-01T00:00:00Z',
                },
            ],
            initialAccountId: 'account-archived',
        });

        // Neither the last-used nor the first account stands in for it: the
        // save is refused instead of landing on another account.
        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            '',
        );

        fillDescriptionAndAmount();
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('Account is required');
        });
        expect(transactionSyncService.create).not.toHaveBeenCalled();
    });

    it('sends a typed amount as an expense, and as income once toggled', async () => {
        // The user types 12.00 either way; the toggle is what decides the sign,
        // so nobody has to type a minus to record a payment.
        const renderDialog = () =>
            render(
                <EditTransactionDialog
                    transaction={manualTransaction}
                    categories={[]}
                    accounts={[checkingAccount]}
                    banks={[]}
                    labels={[]}
                    open
                    onOpenChange={vi.fn()}
                    onSuccess={vi.fn()}
                    mode="edit"
                />,
            );

        const { unmount } = renderDialog();

        // An existing expense opens with Expense selected and an unsigned field.
        expect(screen.getByTestId('transaction-type-expense')).toHaveAttribute(
            'data-state',
            'on',
        );
        expect(screen.getByLabelText('Amount')).not.toHaveValue('-12.00');

        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));
        await waitFor(() =>
            expect(transactionSyncService.update).toHaveBeenCalledOnce(),
        );
        expect(
            vi.mocked(transactionSyncService.update).mock.calls[0][1],
        ).not.toHaveProperty('amount');

        unmount();
        vi.mocked(transactionSyncService.update).mockClear();
        renderDialog();

        fireEvent.click(screen.getByTestId('transaction-type-income'));
        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

        await waitFor(() =>
            expect(transactionSyncService.update).toHaveBeenCalledOnce(),
        );
        expect(
            vi.mocked(transactionSyncService.update).mock.calls[0][1],
        ).toMatchObject({ amount: 1200 });
    });

    it('falls back to the profile currency before an account is picked', () => {
        // With an account to fall back on the chip would pick it, so only a
        // user without one still opens on the profile currency.
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        // inertiaProps puts the profile on EUR; a hardcoded USD used to show
        // here. The symbol has since become a picker, so the same guarantee is
        // read off the code it starts on.
        expect(screen.getByTestId('currency_code-value')).toHaveAttribute(
            'data-value',
            'EUR',
        );
    });

    it('defaults the currency to the account it is being added to', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[{ ...checkingAccount, currency_code: 'USD' }]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        expect(screen.getByTestId('currency_code-value')).toHaveAttribute(
            'data-value',
            'USD',
        );
    });

    it('stores the picked currency rather than the account one', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
            account_id: 'account-1',
            amount: -4000,
            currency_code: 'USD',
        } as never);

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        fireEvent.click(screen.getByText('USD - US Dollar'));

        fillDescriptionAndAmount('Hotel', '40');
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.objectContaining({
                    amount: -4000,
                    currency_code: 'USD',
                }),
                expect.anything(),
            );
        });
    });

    it('shows what a foreign-currency amount comes to in the account currency', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ amount: 3700 }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        fireEvent.click(screen.getByText('USD - US Dollar'));

        const amountInput = screen.getByPlaceholderText('25.00');
        fireEvent.change(amountInput, { target: { value: '40' } });
        fireEvent.blur(amountInput);

        await waitFor(() => {
            expect(screen.getByTestId('converted-amount')).toBeInTheDocument();
        });

        vi.unstubAllGlobals();
    });

    it('shows nothing extra when the transaction is in the account currency', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        const amountInput = screen.getByPlaceholderText('25.00');
        fireEvent.change(amountInput, { target: { value: '40' } });
        fireEvent.blur(amountInput);

        await waitFor(() => {
            expect(
                screen.queryByTestId('converted-amount'),
            ).not.toBeInTheDocument();
        });
        // Same currency on both sides: nothing to ask the server for.
        expect(fetchMock).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('keeps the original alone when no rate can be found', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ amount: null }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        fireEvent.click(screen.getByText('USD - US Dollar'));

        const amountInput = screen.getByPlaceholderText('25.00');
        fireEvent.change(amountInput, { target: { value: '40' } });
        fireEvent.blur(amountInput);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });

        // A figure that could not be converted is never rendered as one.
        expect(
            screen.queryByTestId('converted-amount'),
        ).not.toBeInTheDocument();

        vi.unstubAllGlobals();
    });

    it('offers to update the balance by default in create mode', () => {
        renderCreateDialog();

        expect(screen.getByTestId('balance-chip')).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        fireEvent.click(screen.getByTestId('toggle-more-options'));

        expect(screen.getByRole('checkbox')).toBeChecked();
    });

    it('lets you edit every field of a manually created transaction', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-manual',
                    user_id: 'user-1',
                    account_id: 'account-1',
                    category_id: null,
                    description: 'Groceries',
                    description_iv: null,
                    transaction_date: '2026-05-27',
                    amount: -1200,
                    currency_code: 'EUR',
                    notes: null,
                    notes_iv: null,
                    creditor_name: null,
                    debtor_name: null,
                    source: 'manually_created',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: 'Groceries',
                    decryptedNotes: null,
                    label_ids: [],
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // Amount, date and description render as editable inputs, not read-only text.
        expect(screen.getByPlaceholderText('25.00')).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Transaction description'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('input[type="date"]'),
        ).toBeInTheDocument();
    });

    const importedTransaction = {
        id: 'tx-imported',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Card payment',
        description_iv: null,
        transaction_date: '2026-05-27',
        amount: -1200,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        creditor_name: null,
        debtor_name: null,
        source: 'imported' as const,
        created_at: '2026-05-27T00:00:00Z',
        updated_at: '2026-05-27T00:00:00Z',
        decryptedDescription: 'Card payment',
        decryptedNotes: null,
        label_ids: [],
    };

    it('keeps amount and description read-only for an imported transaction but lets the date move', () => {
        render(
            <EditTransactionDialog
                transaction={importedTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(screen.queryByPlaceholderText('25.00')).not.toBeInTheDocument();
        expect(
            screen.queryByPlaceholderText('Transaction description'),
        ).not.toBeInTheDocument();
        expect(document.querySelector('input[type="date"]')).toHaveValue(
            '2026-05-27',
        );
        expect(
            screen.getByText(
                'The date decides which month and budget this transaction counts towards.',
            ),
        ).toBeInTheDocument();
    });

    it('does not focus the date field when opening an imported transaction', () => {
        render(
            <EditTransactionDialog
                transaction={importedTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        const dialogContent = screen.getByTestId('dialog-content');
        const dateInput = document.querySelector('input[type="date"]');

        expect(document.activeElement).toBe(dialogContent);
        expect(document.activeElement).not.toBe(dateInput);
    });

    it('shows the source date once the transaction has been moved', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    ...importedTransaction,
                    transaction_date: '2026-06-01',
                    source_date: '2026-05-27',
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.getByTestId('original-transaction-date'),
        ).toHaveTextContent('Original date: May 27');
    });

    it('shows the source date as soon as the field moves off it, before saving', () => {
        render(
            <EditTransactionDialog
                transaction={importedTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // Nothing to point at while the field still sits on the source's day.
        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();

        const dateInput = document.querySelector(
            'input[type="date"]',
        ) as HTMLInputElement;
        fireEvent.change(dateInput, { target: { value: '2026-06-01' } });

        expect(
            screen.getByTestId('original-transaction-date'),
        ).toHaveTextContent('Original date: May 27');

        // Moving it back onto the source's own day hides it again.
        fireEvent.change(dateInput, { target: { value: '2026-05-27' } });

        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();
    });

    it('never offers a source date on a manually created transaction', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        const dateInput = document.querySelector(
            'input[type="date"]',
        ) as HTMLInputElement;
        fireEvent.change(dateInput, { target: { value: '2026-06-01' } });

        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();
    });

    // Sentry PHP-LARAVEL-5G: Chromium lets the year segment take six digits, and
    // formatting the "20266-10-01" that leaves behind threw during render.
    // The fixtures are read lazily: manualTransaction is declared further down.
    it.each([
        { label: 'manual', transaction: () => manualTransaction },
        { label: 'imported', transaction: () => importedTransaction },
    ])(
        'keeps rendering a $label transaction when the date field holds a year past four digits',
        ({ transaction }) => {
            render(
                <EditTransactionDialog
                    transaction={transaction()}
                    categories={[]}
                    accounts={[checkingAccount]}
                    banks={[]}
                    labels={[]}
                    open
                    onOpenChange={vi.fn()}
                    onSuccess={vi.fn()}
                    mode="edit"
                />,
            );

            const dateInput = document.querySelector(
                'input[type="date"]',
            ) as HTMLInputElement;
            fireEvent.change(dateInput, { target: { value: '20266-10-01' } });

            expect(dateInput).toHaveValue('20266-10-01');
            expect(dateInput).toHaveAttribute('max', '9999-12-31');
            expect(screen.getByText('Edit Transaction')).toBeInTheDocument();
        },
    );

    // Upstream locks the date on a part of a split, because there each part is
    // a transaction of its own. Here a split lives in transaction_splits and the
    // parent keeps a single date the parts follow, so there is no part-row whose
    // date could drift — the parent's date moves like any other.
    it('lets a split parent move its date', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    ...importedTransaction,
                    splits: [
                        { category_id: 'cat-1', amount: -600 },
                        { category_id: 'cat-2', amount: -400 },
                    ],
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        const dateInput = document.querySelector(
            'input[type="date"]',
        ) as HTMLInputElement;

        expect(dateInput).toHaveValue('2026-05-27');

        fireEvent.change(dateInput, { target: { value: '2026-06-01' } });

        expect(
            screen.getByTestId('original-transaction-date'),
        ).toHaveTextContent('Original date: May 27');
    });

    it('omits unchanged split, category, and amount fields from a notes-only edit', async () => {
        const categories = [
            { id: 'food', name: 'Food', type: 'expense' },
            { id: 'home', name: 'Home', type: 'expense' },
        ] as Category[];
        const transaction = {
            ...manualTransaction,
            id: 'tx-split',
            category_id: null,
            amount: -1200,
            source: 'imported',
            is_split: true,
            split_count: 2,
            splits: [
                {
                    id: 'split-1',
                    transaction_id: 'tx-split',
                    category_id: 'food',
                    category: categories[0],
                    amount: -700,
                    position: 0,
                },
                {
                    id: 'split-2',
                    transaction_id: 'tx-split',
                    category_id: 'home',
                    category: categories[1],
                    amount: -500,
                    position: 1,
                },
            ],
        } as DecryptedTransaction;

        render(
            <EditTransactionDialog
                transaction={transaction}
                categories={categories}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // A transaction with no note starts with the field collapsed.
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
        fireEvent.change(screen.getByLabelText('Notes'), {
            target: { value: 'Receipt checked' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

        await waitFor(() =>
            expect(transactionSyncService.update).toHaveBeenCalledOnce(),
        );
        const payload = vi.mocked(transactionSyncService.update).mock
            .calls[0][1];

        expect(payload).toMatchObject({ notes: 'Receipt checked' });
        expect(payload).not.toHaveProperty('splits');
        expect(payload).not.toHaveProperty('category_id');
        expect(payload).not.toHaveProperty('amount');
    });

    it('uses the authoritative update response for the visible transaction state', async () => {
        const onSuccess = vi.fn();
        vi.mocked(transactionSyncService.update).mockResolvedValueOnce({
            ...manualTransaction,
            updated_at: '2026-07-21T18:00:00Z',
            amount: -1500,
            label_ids: [],
            learned_rule: null,
        });

        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={onSuccess}
                mode="edit"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

        await waitFor(() =>
            expect(onSuccess).toHaveBeenCalledWith(
                expect.objectContaining({
                    updated_at: '2026-07-21T18:00:00Z',
                    amount: -1500,
                }),
            ),
        );
    });

    it('explains why the balance stays put on a connected account', () => {
        const connectedAccount = {
            ...checkingAccount,
            id: 'account-connected',
            banking_connection_id: 'connection-1',
        };

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[connectedAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-connected"
            />,
        );

        expect(screen.queryByTestId('balance-chip')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('toggle-more-options'));

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                "This account's balance comes from your bank, so it won't change.",
            ),
        ).toBeInTheDocument();
    });

    it('updates the balance of a manual account it creates a transaction on', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        expect(screen.getByTestId('balance-chip')).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        fillDescriptionAndAmount('Hotel', '40');
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.objectContaining({
                    account_id: 'account-1',
                    amount: -4000,
                }),
                { updateBalance: true },
            );
        });
    });

    const manualTransaction = {
        id: 'tx-manual',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Groceries',
        description_iv: null,
        transaction_date: '2026-05-27',
        amount: -1200,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        creditor_name: null,
        debtor_name: null,
        source: 'manually_created' as const,
        created_at: '2026-05-27T00:00:00Z',
        updated_at: '2026-05-27T00:00:00Z',
        decryptedDescription: 'Groceries',
        decryptedNotes: null,
        label_ids: [],
    };

    describe('on a credit card', () => {
        const creditCard = {
            ...checkingAccount,
            id: 'account-card',
            name: 'Visa',
            type: 'credit_card' as const,
        };

        function renderCardDialog(
            props: Partial<
                React.ComponentProps<typeof EditTransactionDialog>
            > = {},
        ) {
            return render(
                <EditTransactionDialog
                    transaction={null}
                    categories={[]}
                    accounts={[creditCard]}
                    banks={[]}
                    labels={[]}
                    open
                    onOpenChange={vi.fn()}
                    onSuccess={vi.fn()}
                    mode="create"
                    initialAccountId="account-card"
                    {...props}
                />,
            );
        }

        it('offers no balance update', async () => {
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
            } as never);

            renderCardDialog();

            // Neither the collapsed form's chip nor the expanded form's
            // checkbox: a card has no balance for a transaction to move.
            expect(
                screen.queryByTestId('balance-chip'),
            ).not.toBeInTheDocument();
            fireEvent.click(screen.getByTestId('toggle-more-options'));
            expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
            expect(
                screen.queryByText(
                    "This account's balance comes from your bank, so it won't change.",
                ),
            ).not.toBeInTheDocument();
            fireEvent.click(screen.getByTestId('toggle-more-options'));

            await fillAndSubmit('submit-transaction');

            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.anything(),
                { updateBalance: false },
            );
        });

        it('sends no balance update when adding another on a card', async () => {
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
            } as never);

            renderCardDialog();
            await fillAndSubmit('submit-and-add-another');

            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.anything(),
                { updateBalance: false },
            );
        });

        it('undoes a rule-categorized card transaction without touching a balance', async () => {
            vi.mocked(evaluateRulesForNewTransaction).mockResolvedValue({
                categoryId: 'category-1',
                labelIds: [],
                labels: [],
                note: null,
                noteIv: null,
                rule: { title: 'Hotels' },
            } as never);
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
                category_id: 'category-1',
            } as never);

            renderCardDialog({
                categories: [{ id: 'category-1', name: 'Travel' } as Category],
                automationRules: [{ id: 'rule-1' } as AutomationRule],
            });
            await fillAndSubmit('submit-transaction');

            const [, options] = vi.mocked(toast.success).mock.calls.at(-1)!;
            await (
                options!.cancel as unknown as { onClick: () => unknown }
            ).onClick();

            expect(transactionSyncService.delete).toHaveBeenCalledWith(
                'tx-new',
                { updateBalance: false },
            );
        });

        it('sends no balance update when editing a card transaction', async () => {
            renderCardDialog({
                mode: 'edit',
                initialAccountId: null,
                transaction: {
                    ...manualTransaction,
                    account_id: 'account-card',
                },
            });

            const amountInput = screen.getByLabelText('Amount');
            fireEvent.change(amountInput, { target: { value: '30' } });
            fireEvent.blur(amountInput);
            fireEvent.click(
                screen.getByRole('button', { name: 'Save Changes' }),
            );

            await waitFor(() => {
                expect(transactionSyncService.update).toHaveBeenCalledWith(
                    'tx-manual',
                    expect.objectContaining({ amount: -3000 }),
                    { updateBalance: false },
                );
            });
        });

        it('sends no balance update when the split editor saves a card transaction', async () => {
            const categories = [
                { id: 'food', name: 'Food', type: 'expense' },
                { id: 'home', name: 'Home', type: 'expense' },
            ] as Category[];

            renderCardDialog({
                mode: 'edit',
                initialAccountId: null,
                categories,
                transaction: {
                    ...manualTransaction,
                    account_id: 'account-card',
                    is_split: true,
                    split_count: 2,
                    splits: [
                        {
                            id: 'split-1',
                            transaction_id: manualTransaction.id,
                            category_id: 'food',
                            category: categories[0],
                            amount: -700,
                            position: 0,
                        },
                        {
                            id: 'split-2',
                            transaction_id: manualTransaction.id,
                            category_id: 'home',
                            category: categories[1],
                            amount: -500,
                            position: 1,
                        },
                    ],
                } as DecryptedTransaction,
            });

            const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
            fireEvent.click(
                screen.getByRole('button', { name: 'Remove split' }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Save Changes' }),
            );

            await waitFor(() => {
                expect(transactionSyncService.update).toHaveBeenCalledWith(
                    'tx-manual',
                    expect.objectContaining({ splits: [] }),
                    { updateBalance: false },
                );
            });

            confirm.mockRestore();
        });

        function moveOntoCardAndSave() {
            const cardOption = screen
                .getByTestId('account-value')
                .querySelector('[data-select-value="account-card"]');
            expect(cardOption).not.toBeNull();
            fireEvent.click(cardOption!);
            fireEvent.click(
                screen.getByRole('button', { name: 'Save Changes' }),
            );
        }

        it('still updates the balance when an edit moves a manual account transaction onto a card', async () => {
            // The card side moves nothing, but the manual account the
            // transaction leaves is still reversed by the server.
            renderCardDialog({
                mode: 'edit',
                initialAccountId: null,
                accounts: [checkingAccount, creditCard],
                transaction: manualTransaction,
            });

            moveOntoCardAndSave();

            await waitFor(() => {
                expect(transactionSyncService.update).toHaveBeenCalledWith(
                    'tx-manual',
                    expect.objectContaining({ account_id: 'account-card' }),
                    { updateBalance: true },
                );
            });
        });

        it('sends no balance update when an edit moves a connected account transaction onto a card', async () => {
            const connectedAccount = {
                ...checkingAccount,
                id: 'account-connected',
                banking_connection_id: 'connection-1',
            };

            renderCardDialog({
                mode: 'edit',
                initialAccountId: null,
                accounts: [connectedAccount, creditCard],
                transaction: {
                    ...manualTransaction,
                    account_id: 'account-connected',
                },
            });

            moveOntoCardAndSave();

            await waitFor(() => {
                expect(transactionSyncService.update).toHaveBeenCalledWith(
                    'tx-manual',
                    expect.objectContaining({ account_id: 'account-card' }),
                    { updateBalance: false },
                );
            });
        });
    });

    it('hides the start split control while transaction splitting is disabled', () => {
        inertiaProps.features.transactionSplitting = false;

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        fireEvent.click(screen.getByTestId('toggle-more-options'));

        expect(
            screen.queryByRole('button', { name: 'Split transaction' }),
        ).not.toBeInTheDocument();
    });

    it('shows existing split lines and the remove action while transaction splitting is disabled', () => {
        inertiaProps.features.transactionSplitting = false;
        const transaction = {
            ...manualTransaction,
            is_split: true,
            split_count: 2,
            splits: [
                {
                    id: 'split-1',
                    transaction_id: manualTransaction.id,
                    category_id: 'food',
                    category: null,
                    amount: -700,
                    position: 0,
                },
                {
                    id: 'split-2',
                    transaction_id: manualTransaction.id,
                    category_id: 'home',
                    category: null,
                    amount: -500,
                    position: 1,
                },
            ],
        } as DecryptedTransaction;

        render(
            <EditTransactionDialog
                transaction={transaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Remove split' }),
        ).toBeInTheDocument();
    });

    it('closes the dialog and asks the parent to delete when Delete is clicked', () => {
        const onDelete = vi.fn();
        const onOpenChange = vi.fn();

        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={onOpenChange}
                onSuccess={vi.fn()}
                onDelete={onDelete}
                mode="edit"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(onDelete).toHaveBeenCalledWith(manualTransaction);
    });

    it('hides the Delete button when no onDelete handler is provided', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Delete' }),
        ).not.toBeInTheDocument();
    });

    it('hides the Delete button in create mode', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                onDelete={vi.fn()}
                mode="create"
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Delete' }),
        ).not.toBeInTheDocument();
    });

    it('opens the create form collapsed, with the rest behind More options', () => {
        renderCreateDialog();

        expect(screen.queryByText('Category')).not.toBeInTheDocument();
        expect(screen.queryByText('Labels')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Date')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Add note/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Split transaction' }),
        ).not.toBeInTheDocument();
        expect(screen.getByTestId('date-chip')).toBeInTheDocument();
        expect(screen.getByTestId('balance-chip')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('toggle-more-options'));

        expect(screen.getByText('Category')).toBeInTheDocument();
        expect(screen.getByText('Labels')).toBeInTheDocument();
        expect(screen.getByLabelText('Date')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Split transaction' }),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('date-chip')).not.toBeInTheDocument();
    });

    it('leaves edit mode showing every field, with nothing to expand', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(screen.getByText('Category')).toBeInTheDocument();
        expect(screen.getByLabelText('Date')).toBeInTheDocument();
        expect(
            screen.queryByTestId('toggle-more-options'),
        ).not.toBeInTheDocument();
        expect(screen.queryByTestId('date-chip')).not.toBeInTheDocument();
        expect(
            screen.queryByTestId('submit-and-add-another'),
        ).not.toBeInTheDocument();
    });

    it('opens on the account the last manual transaction went to', () => {
        vi.mocked(localStorage.getItem).mockImplementation((key: string) =>
            key === 'whisper_money_last_transaction_account'
                ? 'account-2'
                : null,
        );

        renderCreateDialog({
            accounts: [
                checkingAccount,
                { ...checkingAccount, id: 'account-2' },
            ],
            initialAccountId: null,
        });

        expect(screen.getByTestId('account-chip')).toBeInTheDocument();
        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            'account-2',
        );
    });

    it('reveals the date field from the date chip', () => {
        renderCreateDialog();

        expect(screen.getByTestId('date-chip')).toHaveTextContent('Today');

        fireEvent.click(screen.getByTestId('date-chip'));

        expect(screen.getByLabelText('Date')).toBeInTheDocument();
    });

    it('capitalizes the month on the date chip for a locale that lowercases it', () => {
        // Hungarian writes the month first and in lowercase ("január 15.").
        localeState.locale = 'hu';
        const day = todayDateString().endsWith('-01-15') ? '16' : '15';
        renderCreateDialog();

        fireEvent.click(screen.getByTestId('date-chip'));
        fireEvent.change(screen.getByLabelText('Date'), {
            target: { value: `${new Date().getFullYear()}-01-${day}` },
        });

        expect(screen.getByTestId('date-chip')).toHaveTextContent(
            `Január ${day}.`,
        );
    });

    it('turns the balance off from the chip and remembers the choice', () => {
        renderCreateDialog();

        fireEvent.click(screen.getByTestId('balance-chip'));

        const chip = screen.getByTestId('balance-chip');
        expect(chip).toHaveAttribute('aria-pressed', 'false');
        expect(chip).toHaveTextContent('Leaves the balance alone');
        expect(localStorage.setItem).toHaveBeenCalledWith(
            'whisper_money_update_balance_on_transaction',
            'false',
        );
    });

    it('keeps the dialog open and the account when adding another', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);
        const onOpenChange = vi.fn();

        renderCreateDialog({ onOpenChange });
        await fillAndSubmit('submit-and-add-another');

        expect(onOpenChange).not.toHaveBeenCalled();
        expect(
            screen.getByPlaceholderText('Transaction description'),
        ).toHaveValue('');
        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            'account-1',
        );
        expect(localStorage.setItem).toHaveBeenCalledWith(
            'whisper_money_last_transaction_account',
            'account-1',
        );
        await waitFor(() => {
            expect(screen.getByPlaceholderText('25.00')).toHaveFocus();
        });
    });

    // getStoredKey returns null on purpose: an account off the legacy
    // encryption has no key, and rules still have to run for it.
    it('names the category a rule picked while the form was collapsed', async () => {
        vi.mocked(evaluateRulesForNewTransaction).mockResolvedValue({
            categoryId: 'category-1',
            labelIds: [],
            labels: [],
            note: null,
            noteIv: null,
            rule: { title: 'Supermarkets' },
        } as never);
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
            category_id: 'category-1',
        } as never);
        const onRequestEdit = vi.fn();

        renderCreateDialog({
            categories: [{ id: 'category-1', name: 'Groceries' } as Category],
            automationRules: [{ id: 'rule-1' } as AutomationRule],
            onRequestEdit,
        });
        await fillAndSubmit('submit-transaction');

        const [message, options] = vi.mocked(toast.success).mock.calls.at(-1)!;
        expect(message).toBe('Transaction saved');
        expect(options!.description).toBe('A rule categorized it as Groceries');

        // Sonner types both as a node or an action; the dialog passes actions.
        type ToastAction = { onClick: () => unknown };
        (options!.action as unknown as ToastAction).onClick();
        expect(onRequestEdit).toHaveBeenCalled();

        await (options!.cancel as unknown as ToastAction).onClick();
        expect(transactionSyncService.delete).toHaveBeenCalledWith('tx-new', {
            updateBalance: true,
        });
        expect(captureEvent).toHaveBeenCalledWith(
            'transaction_created',
            expect.objectContaining({ rule_applied_category: true }),
        );
    });

    it('reports every created transaction with how it was entered', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

        renderCreateDialog({ origin: 'quick_add' });
        await fillAndSubmit('submit-transaction');

        expect(captureEvent).toHaveBeenCalledWith('transaction_created', {
            source: 'manually_created',
            origin: 'quick_add',
            expanded: false,
            saved_and_added_another: false,
            account_chip_changed: false,
            date_chip_changed: false,
            category_chip_changed: false,
            rule_applied_category: false,
        });
    });

    const groceries = { id: 'category-1', name: 'Groceries' } as Category;

    it('sets the category from the chip without expanding the form', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

        renderCreateDialog({ categories: [groceries] });

        const chip = screen.getByTestId('category-chip');
        expect(chip).toHaveAttribute('data-value', 'null');
        expect(screen.queryByTestId('category-select')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Groceries'));
        await fillAndSubmit('submit-transaction');

        expect(transactionSyncService.create).toHaveBeenCalledWith(
            expect.objectContaining({ category_id: 'category-1' }),
            expect.anything(),
        );
        expect(captureEvent).toHaveBeenCalledWith(
            'transaction_created',
            expect.objectContaining({ category_chip_changed: true }),
        );
    });

    it('keeps the category the chip holds when adding another', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

        renderCreateDialog({ categories: [groceries] });

        fireEvent.click(screen.getByText('Groceries'));
        await fillAndSubmit('submit-and-add-another');

        expect(screen.getByTestId('category-chip')).toHaveAttribute(
            'data-value',
            'category-1',
        );
    });

    it('keeps a rule from overwriting what the chip picked', async () => {
        vi.mocked(evaluateRulesForNewTransaction).mockReturnValue({
            categoryId: 'category-2',
            labelIds: [],
            labels: [],
            note: null,
            rule: { title: 'Supermarkets' },
        } as never);
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

        renderCreateDialog({
            categories: [groceries],
            automationRules: [{ id: 'rule-1' } as AutomationRule],
        });

        fireEvent.click(screen.getByText('Groceries'));
        await fillAndSubmit('submit-transaction');

        // The chip's pick wins: the rule only fills a category nobody chose.
        expect(transactionSyncService.create).toHaveBeenCalledWith(
            expect.objectContaining({ category_id: 'category-1' }),
            expect.anything(),
        );
        expect(captureEvent).toHaveBeenCalledWith(
            'transaction_created',
            expect.objectContaining({ rule_applied_category: false }),
        );
    });

    it('shows the category chip and the expanded field bound to the same pick', () => {
        renderCreateDialog({ categories: [groceries] });

        fireEvent.click(screen.getByText('Groceries'));
        fireEvent.click(screen.getByTestId('toggle-more-options'));

        expect(screen.queryByTestId('category-chip')).not.toBeInTheDocument();
        expect(screen.getByTestId('category-select')).toHaveAttribute(
            'data-value',
            'category-1',
        );
    });

    describe('splitting while creating', () => {
        const splitCategories = [
            { id: 'food', name: 'Food', type: 'expense' },
            { id: 'home', name: 'Home', type: 'expense' },
        ] as Category[];

        function startSplit() {
            fireEvent.click(screen.getByTestId('toggle-more-options'));
            fireEvent.click(
                screen.getByRole('button', { name: 'Split transaction' }),
            );
        }

        function fillSplitLines() {
            fireEvent.click(
                within(screen.getByTestId('split-category-0')).getByText(
                    'Food',
                ),
            );
            fireEvent.click(
                within(screen.getByTestId('split-category-1')).getByText(
                    'Home',
                ),
            );
            for (const [label, value] of [
                ['Amount 1', '-15'],
                ['Amount 2', '-10'],
            ]) {
                const input = screen.getByLabelText(label);
                fireEvent.change(input, { target: { value } });
                fireEvent.blur(input);
            }
        }

        it('reaches the split editor behind More options and sends its lines', async () => {
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
            } as never);

            renderCreateDialog({ categories: splitCategories });

            fillDescriptionAndAmount();

            startSplit();
            fillSplitLines();
            fireEvent.click(screen.getByTestId('submit-transaction'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledWith(
                    expect.objectContaining({
                        amount: -2500,
                        category_id: null,
                        splits: [
                            { category_id: 'food', amount: -1500, position: 0 },
                            { category_id: 'home', amount: -1000, position: 1 },
                        ],
                    }),
                    { updateBalance: true },
                );
            });
        });

        it('cannot collapse while a split is in progress', () => {
            renderCreateDialog({ categories: splitCategories });

            startSplit();

            expect(
                screen.queryByTestId('toggle-more-options'),
            ).not.toBeInTheDocument();
            expect(screen.queryByTestId('date-chip')).not.toBeInTheDocument();
            expect(
                screen.queryByTestId('category-chip'),
            ).not.toBeInTheDocument();
            expect(screen.getByTestId('split-category-0')).toBeInTheDocument();

            const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
            fireEvent.click(
                screen.getByRole('button', { name: 'Remove split' }),
            );
            confirm.mockRestore();

            expect(screen.getByTestId('toggle-more-options')).toHaveTextContent(
                'Fewer options',
            );
        });

        it('returns to the chips once a split is removed and the form collapsed', () => {
            renderCreateDialog({ categories: splitCategories });

            startSplit();
            const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
            fireEvent.click(
                screen.getByRole('button', { name: 'Remove split' }),
            );
            confirm.mockRestore();
            fireEvent.click(screen.getByTestId('toggle-more-options'));

            expect(screen.getByTestId('date-chip')).toBeInTheDocument();
            expect(screen.getByTestId('category-chip')).toBeInTheDocument();
            expect(
                screen.queryByTestId('split-category-0'),
            ).not.toBeInTheDocument();
        });

        it('drops the split lines when adding another', async () => {
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
            } as never);

            renderCreateDialog({ categories: splitCategories });

            fillDescriptionAndAmount();

            startSplit();
            fillSplitLines();
            fireEvent.click(screen.getByTestId('submit-and-add-another'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledWith(
                    expect.objectContaining({
                        splits: expect.arrayContaining([
                            expect.objectContaining({ category_id: 'food' }),
                        ]),
                    }),
                    expect.anything(),
                );
            });
            // The lines added up to the amount just saved; the next entry
            // starts on the plain category field.
            await waitFor(() => {
                expect(
                    screen.getByRole('button', { name: 'Split transaction' }),
                ).toBeInTheDocument();
            });
        });
    });

    describe('duplicating', () => {
        const savingsAccount = {
            ...checkingAccount,
            id: 'account-2',
            name: 'Savings',
        };

        const splitCategories = [
            { id: 'food', name: 'Food', type: 'expense' },
            { id: 'home', name: 'Home', type: 'expense' },
        ] as Category[];

        const monthlyRent: DecryptedTransaction = {
            ...manualTransaction,
            id: 'tx-rent',
            account_id: 'account-2',
            description: 'Rent',
            transaction_date: '2026-01-01',
            amount: -85000,
            currency_code: 'USD',
            category_id: 'category-1',
            label_ids: ['label-1'],
            source: 'enablebanking',
            decryptedDescription: 'Rent',
            decryptedNotes: 'Flat on Main St',
        };

        // A split parent carries no category of its own; its lines do. They
        // come out of order here to show the copy follows their positions.
        const splitShopping: DecryptedTransaction = {
            ...monthlyRent,
            id: 'tx-shopping',
            description: 'Hypermarket',
            decryptedDescription: 'Hypermarket',
            amount: -2500,
            currency_code: 'EUR',
            category_id: null,
            is_split: true,
            split_count: 2,
            splits: [
                {
                    id: 'split-2',
                    transaction_id: 'tx-shopping',
                    category_id: 'home',
                    category: null,
                    amount: -1000,
                    position: 1,
                },
                {
                    id: 'split-1',
                    transaction_id: 'tx-shopping',
                    category_id: 'food',
                    category: null,
                    amount: -1500,
                    position: 0,
                },
            ],
        };

        beforeEach(() => {
            vi.mocked(transactionSyncService.create).mockResolvedValue({
                id: 'tx-new',
            } as never);
        });

        it('fills a duplicate in from the original, dated today', async () => {
            renderCreateDialog({
                accounts: [checkingAccount, savingsAccount],
                categories: [groceries],
                automationRules: [{ id: 'rule-1' } as AutomationRule],
                duplicateFrom: monthlyRent,
            });

            expect(screen.getByTestId('account-value')).toHaveAttribute(
                'data-value',
                'account-2',
            );

            fireEvent.click(screen.getByTestId('submit-transaction'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledWith(
                    expect.objectContaining({
                        account_id: 'account-2',
                        description: 'Rent',
                        amount: -85000,
                        currency_code: 'USD',
                        category_id: 'category-1',
                        label_ids: ['label-1'],
                        notes: 'Flat on Main St',
                        transaction_date: todayDateString(),
                        source: 'manually_created',
                        splits: undefined,
                    }),
                    { updateBalance: true },
                );
            });
            // The copy keeps what the user settled on; no rule gets a say.
            expect(evaluateRulesForNewTransaction).not.toHaveBeenCalled();
            expect(captureEvent).toHaveBeenCalledWith(
                'transaction_created',
                expect.objectContaining({
                    origin: 'duplicate',
                    category_chip_changed: false,
                }),
            );
        });

        it('falls back to the usual account when the original one is gone', () => {
            renderCreateDialog({ duplicateFrom: monthlyRent });

            expect(screen.getByTestId('account-value')).toHaveAttribute(
                'data-value',
                'account-1',
            );
        });

        it('treats what comes after a duplicate as a transaction of its own', async () => {
            renderCreateDialog({
                accounts: [checkingAccount, savingsAccount],
                automationRules: [{ id: 'rule-1' } as AutomationRule],
                duplicateFrom: monthlyRent,
            });

            fireEvent.click(screen.getByTestId('submit-and-add-another'));
            await waitFor(() => {
                expect(captureEvent).toHaveBeenCalledTimes(1);
            });
            await fillAndSubmit('submit-transaction');
            await waitFor(() => {
                expect(captureEvent).toHaveBeenCalledTimes(2);
            });

            expect(evaluateRulesForNewTransaction).toHaveBeenCalledTimes(1);
            expect(
                vi.mocked(captureEvent).mock.calls.map(([, props]) => props),
            ).toEqual([
                expect.objectContaining({ origin: 'duplicate' }),
                expect.objectContaining({ origin: 'full_dialog' }),
            ]);
        });

        it('copies the split lines, in order, and opens on them', async () => {
            renderCreateDialog({
                accounts: [checkingAccount, savingsAccount],
                categories: splitCategories,
                duplicateFrom: splitShopping,
            });

            // The form cannot collapse over lines in progress.
            expect(screen.getByTestId('split-category-0')).toBeInTheDocument();
            expect(
                screen.queryByTestId('toggle-more-options'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByTestId('category-chip'),
            ).not.toBeInTheDocument();

            fireEvent.click(screen.getByTestId('submit-transaction'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledOnce();
            });
            const payload = vi.mocked(transactionSyncService.create).mock
                .calls[0][0];
            expect(payload).toMatchObject({
                account_id: 'account-2',
                description: 'Hypermarket',
                amount: -2500,
                category_id: null,
                label_ids: ['label-1'],
                notes: 'Flat on Main St',
                transaction_date: todayDateString(),
            });
            expect(payload.splits).toEqual([
                { category_id: 'food', amount: -1500, position: 0 },
                { category_id: 'home', amount: -1000, position: 1 },
            ]);
            expect(
                payload.splits?.reduce((sum, line) => sum + line.amount, 0),
            ).toBe(payload.amount);
            expect(captureEvent).toHaveBeenCalledWith(
                'transaction_created',
                expect.objectContaining({
                    origin: 'duplicate',
                    expanded: true,
                }),
            );
        });

        it('drops the copied lines when adding another after it', async () => {
            renderCreateDialog({
                accounts: [checkingAccount, savingsAccount],
                categories: splitCategories,
                duplicateFrom: splitShopping,
            });

            fireEvent.click(screen.getByTestId('submit-and-add-another'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledOnce();
            });
            expect(
                vi.mocked(transactionSyncService.create).mock.calls[0][0]
                    .splits,
            ).toHaveLength(2);
            await waitFor(() => {
                expect(
                    screen.getByRole('button', { name: 'Split transaction' }),
                ).toBeInTheDocument();
            });

            await fillAndSubmit('submit-transaction');
            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledTimes(2);
            });
            expect(
                vi.mocked(transactionSyncService.create).mock.calls[1][0]
                    .splits,
            ).toBeUndefined();
        });

        it('copies no lines while splitting is switched off', async () => {
            inertiaProps.features.transactionSplitting = false;

            renderCreateDialog({
                accounts: [checkingAccount, savingsAccount],
                categories: splitCategories,
                duplicateFrom: splitShopping,
            });

            expect(
                screen.getByTestId('toggle-more-options'),
            ).toBeInTheDocument();
            fireEvent.click(screen.getByTestId('submit-transaction'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledOnce();
            });
            const payload = vi.mocked(transactionSyncService.create).mock
                .calls[0][0];
            expect(payload.splits).toBeUndefined();
            expect(payload.category_id).toBeNull();
        });

        it('sends no balance update when the original sits on a credit card', async () => {
            const creditCard = {
                ...checkingAccount,
                id: 'account-card',
                name: 'Visa',
                type: 'credit_card' as const,
            };

            renderCreateDialog({
                accounts: [checkingAccount, creditCard],
                categories: splitCategories,
                duplicateFrom: {
                    ...splitShopping,
                    account_id: 'account-card',
                },
            });

            fireEvent.click(screen.getByTestId('submit-transaction'));

            await waitFor(() => {
                expect(transactionSyncService.create).toHaveBeenCalledWith(
                    expect.objectContaining({ account_id: 'account-card' }),
                    { updateBalance: false },
                );
            });
        });
    });
});
