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
                        {dialog.isAllMatching
                            ? __(
                                  'Are you sure you want to remove every label from all the transactions matching the filters? This action cannot be undone.',
                              )
                            : __(
                                  'Are you sure you want to remove every label from :count transactions? This action cannot be undone.',
                                  { count: dialog.selectedCount },
                              )}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>{__('Cancel')}</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={dialog.onConfirm}
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
