import {
    EditTransactionDialog,
    type TransactionCreateOrigin,
} from '@/components/transactions/edit-transaction-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { ICON_ONLY_BELOW_SM } from '@/lib/icon-only-below-sm';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { cn } from '@/lib/utils';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';

/**
 * "+ Transaction", the face of every add-transaction button above a
 * transactions list. A collapsible label drops to the icon alone below `sm`;
 * the accessible name stays on the button's aria-label.
 */
export function AddTransactionLabel({
    collapsible = false,
}: {
    collapsible?: boolean;
}) {
    return (
        <>
            <Plus className="h-4 w-4" />
            <span
                className={collapsible ? ICON_ONLY_BELOW_SM.label : undefined}
            >
                {__('Transaction')}
            </span>
        </>
    );
}

interface AddTransactionButtonProps {
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    /**
     * How the page underneath learns about what was written, once the dialog
     * closes. By default the page is visited again, which remounts it and
     * this button with it, so the rule toast offers only "Undo"; a page that
     * can refresh its rows in place passes that instead, and the button stays
     * mounted to reopen the dialog for the toast's "Change category".
     */
    onSaved?: () => void;
    /**
     * A label created from inside the dialog. A page that refreshes in place
     * only reloads its rows, so it needs this to show the new label in its
     * filters and pickers without a full visit.
     */
    onLabelCreated?: (label: Label) => void;
    variant?: 'default' | 'outline';
    /** Which surface the button sits on, for `transaction_created`. */
    origin?: TransactionCreateOrigin;
    testId?: string;
}

/**
 * Adding a transaction by hand, from right above the transactions list so
 * what was just entered can be checked without scrolling back up. A quarter
 * of active users never connect a bank, so this is their only way in.
 */
export function AddTransactionButton({
    hasTransactionalAccounts,
    onSaved,
    onLabelCreated,
    variant = 'outline',
    origin = 'quick_add',
    testId = 'add-transaction-button',
}: AddTransactionButtonProps) {
    const { data, loading, load } = useTransactionDialogData();
    const [open, setOpen] = useState(false);
    // Set only by the toast's "Change category", which reopens the same dialog
    // on the transaction a rule just filed away out of sight.
    const [editing, setEditing] = useState<DecryptedTransaction | null>(null);
    // A ref, not state: the dialog reports the save and closes in the same
    // batch, so a state flag would still read false in the close handler.
    const savedSomething = useRef(false);

    const isDisabled = !hasTransactionalAccounts;

    async function handleOpen() {
        if (isDisabled || loading) {
            return;
        }

        if (await load()) {
            setOpen(true);
        }
    }

    function handleOpenChange(next: boolean) {
        setOpen(next);

        if (next) {
            return;
        }

        setEditing(null);

        // The page underneath was rendered before any of this existed, so it
        // only learns about the new rows once the dialog is out of the way.
        if (savedSomething.current) {
            savedSomething.current = false;
            (onSaved ?? refreshPageAfterWrite)();
        }
    }

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant={variant}
                            className={cn(
                                ICON_ONLY_BELOW_SM.button,
                                (isDisabled || loading) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                            onClick={handleOpen}
                            aria-disabled={isDisabled || loading}
                            aria-label={__('Add transaction')}
                            data-testid={testId}
                        >
                            <AddTransactionLabel collapsible />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {isDisabled
                            ? __('You need an account to record transactions')
                            : __('Create a new transaction')}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            {data && (
                <EditTransactionDialog
                    transaction={editing}
                    categories={data.categories}
                    accounts={data.accounts}
                    banks={data.banks}
                    labels={data.labels}
                    automationRules={data.automationRules}
                    open={open}
                    onOpenChange={handleOpenChange}
                    onSuccess={() => {
                        savedSomething.current = true;
                    }}
                    mode={editing ? 'edit' : 'create'}
                    origin={origin}
                    onLabelCreated={onLabelCreated}
                    // Only while this button outlives the refresh: a full
                    // visit remounts it, and a "Change category" left on the
                    // toast would reopen nothing.
                    onRequestEdit={
                        onSaved
                            ? (created) => {
                                  setEditing(created);
                                  setOpen(true);
                              }
                            : undefined
                    }
                />
            )}
        </>
    );
}
