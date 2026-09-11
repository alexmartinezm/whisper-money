import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';

export interface TransactionRowAction {
    id: string;
    label: string;
    onSelect: () => void;
    variant?: 'destructive';
}

interface TransactionRowActionsOptions {
    transaction: DecryptedTransaction;
    onEdit: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
    onAutomate: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
}

/**
 * What a row offers, shared by the dropdown in the actions column and the
 * right-click menus on the row itself, so they can never drift apart.
 *
 * Splitting is not here: in this fork a split is edited from the transaction
 * dialog, where its postings live.
 */
export function getTransactionRowActions({
    transaction,
    onEdit,
    onReEvaluateRules,
    onAutomate,
    onDelete,
}: TransactionRowActionsOptions): TransactionRowAction[] {
    return [
        {
            id: 'edit',
            label: __('Edit'),
            onSelect: () => onEdit(transaction),
        },
        {
            id: 're-evaluate-rules',
            label: __('Re-evaluate rules'),
            onSelect: () => onReEvaluateRules(transaction),
        },
        {
            id: 'automate',
            label: __('Automatize categorization'),
            onSelect: () => onAutomate(transaction),
        },
        {
            id: 'delete',
            label: __('Delete'),
            onSelect: () => onDelete(transaction),
            variant: 'destructive',
        },
    ];
}
