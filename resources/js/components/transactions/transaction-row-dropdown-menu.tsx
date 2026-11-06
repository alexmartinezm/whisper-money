import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import { type TransactionRowAction } from '@/lib/transaction-row-actions';
import { __ } from '@/utils/i18n';
import { type ComponentProps } from 'react';

/**
 * The dropdown of a transaction's row actions, the same in the table's
 * actions column and in the edit dialog on a phone.
 */
export function TransactionRowDropdownMenuContent({
    actions,
    ...props
}: { actions: TransactionRowAction[] } & ComponentProps<
    typeof DropdownMenuContent
>) {
    return (
        <DropdownMenuContent {...props}>
            <DropdownMenuLabel>{__('Actions')}</DropdownMenuLabel>
            {actions.map((action) => (
                <DropdownMenuItem
                    key={action.id}
                    variant={action.variant}
                    onClick={action.onSelect}
                >
                    {action.label}
                </DropdownMenuItem>
            ))}
        </DropdownMenuContent>
    );
}
