import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { type TransactionRemoveLabelsDialogState } from '@/hooks/use-transaction-bulk-actions';
import { __ } from '@/utils/i18n';

/**
 * What the removal reaches, in words. A select-all has no number of its own
 * and with no filter set it is the whole ledger, so it says that, and how many.
 */
function removalDescription({
    isAllMatching,
    hasFilters,
    selectedCount,
    matchingCount,
    countFailed,
}: TransactionRemoveLabelsDialogState): string {
    if (!isAllMatching) {
        return __(
            'Are you sure you want to remove every label from :count transactions? This action cannot be undone.',
            { count: selectedCount },
        );
    }

    if (!hasFilters) {
        return matchingCount === null
            ? __(
                  'No filter is set: this removes every label from all your transactions. This action cannot be undone.',
              )
            : __(
                  'No filter is set: this removes every label from all your :count transactions. This action cannot be undone.',
                  { count: matchingCount },
              );
    }

    if (matchingCount !== null) {
        return __(
            'Are you sure you want to remove every label from the :count transactions matching the filters? This action cannot be undone.',
            { count: matchingCount },
        );
    }

    return countFailed
        ? __(
              'The transactions matching the filters could not be counted, so their labels cannot be removed. Cancel and try again.',
          )
        : __('Counting the transactions matching the filters...');
}

/** The confirmation before the bulk bar takes every label off the rows. */
export function TransactionRemoveLabelsDialog({
    dialog,
}: {
    dialog: TransactionRemoveLabelsDialogState;
}) {
    return (
        <AlertDialog
            open={dialog.isOpen}
            onOpenChange={(open) => !open && dialog.onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {__('Remove all labels')}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {removalDescription(dialog)}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>{__('Cancel')}</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={dialog.onConfirm}
                        disabled={!dialog.canConfirm}
                        className="bg-red-600 hover:bg-red-700"
                        data-testid="confirm-remove-all-labels"
                    >
                        {__('Remove all labels')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
