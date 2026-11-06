import {
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
} from '@/components/ui/context-menu';
import {
    getTransactionRowActions,
    type TransactionRowActionHandlers,
} from '@/lib/transaction-row-actions';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';

/**
 * The right-click menu of a transaction, the same on a table row of either
 * page and on a card.
 */
export function TransactionRowContextMenuContent({
    transaction,
    ...handlers
}: TransactionRowActionHandlers & { transaction: DecryptedTransaction }) {
    return (
        <ContextMenuContent>
            <ContextMenuLabel>{__('Actions')}</ContextMenuLabel>
            {getTransactionRowActions({ transaction, ...handlers }).map(
                (action) => (
                    <ContextMenuItem
                        key={action.id}
                        onClick={action.onSelect}
                        variant={action.variant}
                    >
                        {action.label}
                    </ContextMenuItem>
                ),
            )}
        </ContextMenuContent>
    );
}
