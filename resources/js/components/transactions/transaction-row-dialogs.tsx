import { AutomateCategorizationDialog } from '@/components/automation-rules/automate-categorization-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { useRef } from 'react';

interface TransactionRowDialogsProps {
    editing: TransactionRowEditing;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    automationRules: AutomationRule[];
    /** Brings the new rows in once the duplicate dialog closes on a save. */
    onDuplicated: () => void;
}

/**
 * The dialogs a row action opens: edit, duplicate and automate. A split is
 * edited from the edit dialog, where its lines live. Delete is not here, as it
 * shares its confirmation with the bulk delete of each table.
 */
export function TransactionRowDialogs({
    editing,
    categories,
    accounts,
    banks,
    automationRules,
    onDuplicated,
}: TransactionRowDialogsProps) {
    // A ref, not state: the dialog reports the save and closes in the same
    // batch, so a state flag would still read false in the close handler.
    const savedDuplicate = useRef(false);

    return (
        <>
            <EditTransactionDialog
                transaction={editing.editTransaction}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={editing.labels}
                automationRules={automationRules}
                open={!!editing.editTransaction}
                onOpenChange={(open) =>
                    !open && editing.setEditTransaction(null)
                }
                onSuccess={editing.updateTransaction}
                onCategorized={editing.handleCategorized}
                onLabelCreated={editing.handleLabelCreated}
                onDelete={editing.setDeleteTransaction}
                rowActions={editing.dialogRowActions}
                mode="edit"
            />

            <EditTransactionDialog
                transaction={null}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={editing.labels}
                automationRules={automationRules}
                open={!!editing.duplicateTransaction}
                onOpenChange={(open) => {
                    if (open) {
                        return;
                    }
                    editing.setDuplicateTransaction(null);
                    // Reloading on the save itself could take "Save and add
                    // another" down with the page, so it waits for the close.
                    if (savedDuplicate.current) {
                        savedDuplicate.current = false;
                        onDuplicated();
                    }
                }}
                onSuccess={() => {
                    savedDuplicate.current = true;
                }}
                onLabelCreated={editing.handleLabelCreated}
                mode="create"
                duplicateFrom={editing.duplicateTransaction}
            />

            <AutomateCategorizationDialog
                open={editing.automateDialogOpen}
                candidate={editing.automateCandidate}
                categories={categories}
                onOpenChange={editing.setAutomateDialogOpen}
            />

            <PostSaveApplyRulePrompt />
        </>
    );
}
