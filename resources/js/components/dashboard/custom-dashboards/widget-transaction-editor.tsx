import { show as showTransaction } from '@/actions/App/Http/Controllers/Api/TransactionController';
import { TransactionDeleteDialogs } from '@/components/transactions/transaction-delete-dialogs';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { useTransactionBulkActions } from '@/hooks/use-transaction-bulk-actions';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { fetchJson } from '@/lib/fetch-json';
import { normalizeTransaction } from '@/lib/normalize-transaction';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type DecryptedTransaction,
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { type RowSelectionState } from '@tanstack/react-table';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/** The editor lists no rows of its own: no filters, nothing to count. */
const NO_FILTERS: TransactionFilters = {
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
};

const countNothing = async (): Promise<number> => 0;

/** A tap on a widget's row: the transaction to open, and which tap it was. */
export interface WidgetTransactionRequest {
    transactionId: string;
    /** Tapping the same row again opens it again. */
    sequence: number;
}

/**
 * The dialogs a tap on a widget's transaction opens: the same edit dialog,
 * row actions and delete confirmation as a transactions table, through the
 * same hooks. It loads the transaction by id, as the server holds it (a
 * split one with its lines, edited in the edit dialog), since a widget only
 * has the API row (and draws a copy of it). After anything changes (a save,
 * a split's lines, a delete) the dashboard asks for its widgets' data again.
 */
export default function WidgetTransactionEditor({
    request,
    onChanged,
}: {
    request: WidgetTransactionRequest;
    onChanged: () => void;
}) {
    const { accounts, categories, banks, labels, automationRules } = usePage<
        SharedData & {
            accounts: Account[];
            categories: Category[];
            banks: Bank[];
            labels: Label[];
            automationRules: AutomationRule[];
        }
    >().props;
    const [rows, setRows] = useState<DecryptedTransaction[]>([]);
    // Nothing is ever ticked here: the bulk actions hook is what owns the
    // delete confirmation the edit dialog's Delete opens.
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

    const editing = useTransactionRowEditing({
        initialLabels: labels,
        setRows,
        onRowUpdated: onChanged,
    });
    const bulk = useTransactionBulkActions({
        rows,
        setRows,
        rowSelection,
        setRowSelection,
        accounts,
        editing,
        filters: NO_FILTERS,
        countMatching: countNothing,
        onTransactionsChanged: onChanged,
    });
    const { setEditTransaction } = editing;

    useEffect(() => {
        let current = true;

        // A tap that fails to load must not open the previous transaction.
        setEditTransaction(null);
        setRows([]);

        fetchJson<{ data: ServerTransaction }>(
            showTransaction.url(request.transactionId),
        ).then(
            ({ data }) => {
                if (current) {
                    const transaction = normalizeTransaction(data);

                    setRows([transaction]);
                    setEditTransaction(transaction);
                }
            },
            () => {
                if (current) {
                    toast.error(__('The transaction could not be opened.'));
                }
            },
        );

        return () => {
            current = false;
        };
    }, [request, setEditTransaction]);

    return (
        <>
            <TransactionRowDialogs
                editing={editing}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onDuplicated={onChanged}
            />
            <TransactionDeleteDialogs dialog={bulk.deleteDialog} />
        </>
    );
}
