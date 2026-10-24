import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { cn } from '@/lib/utils';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';

interface AddTransactionButtonProps {
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    /**
     * How the page underneath learns about what was written, once the dialog
     * closes. By default the page is visited again, which remounts it; a page
     * that can refresh its rows in place passes that instead, so this button
     * stays mounted for the rule toast's "Change category".
     */
    onSaved?: () => void;
    /**
     * A label created from inside the dialog. A page that refreshes in place
     * only reloads its rows, so it needs this to show the new label in its
     * filters and pickers without a full visit.
     */
    onLabelCreated?: (label: Label) => void;
}

/**
 * Adding a transaction by hand, from right above the transactions list so
 * what was just entered can be checked without scrolling back up. A quarter
 * of active users never connect a bank, so this is their only way in.
 */
export function AddTransactionButton({
    hasTransactionalAccounts,
    onSaved = refreshPageAfterWrite,
    onLabelCreated,
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
            onSaved();
        }
    }

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="outline"
                            className={cn(
                                (isDisabled || loading) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                            onClick={handleOpen}
                            aria-disabled={isDisabled || loading}
                            aria-label={__('Add transaction')}
                            data-testid="add-transaction-button"
                        >
                            <Plus className="h-5 w-5" />
                            <span className="hidden sm:inline">
                                {__('Transaction')}
                            </span>
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
                    origin="quick_add"
                    onLabelCreated={onLabelCreated}
                    onRequestEdit={(created) => {
                        setEditing(created);
                        setOpen(true);
                    }}
                />
            )}
        </>
    );
}
