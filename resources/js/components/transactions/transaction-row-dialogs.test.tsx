import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { type DecryptedTransaction } from '@/types/transaction';
import { act, render } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { EditTransactionDialog } from './edit-transaction-dialog';
import { TransactionRowDialogs } from './transaction-row-dialogs';

type DialogProps = ComponentProps<typeof EditTransactionDialog>;

const dialogs = vi.hoisted(() => [] as DialogProps[]);

vi.mock('./edit-transaction-dialog', () => ({
    EditTransactionDialog: (props: DialogProps) => {
        dialogs.push(props);

        return null;
    },
}));
vi.mock('@/components/automation-rules/automate-categorization-dialog', () => ({
    AutomateCategorizationDialog: () => null,
}));
vi.mock('@/components/automation-rules/post-save-apply-rule-prompt', () => ({
    PostSaveApplyRulePrompt: () => null,
}));

function renderDialogs() {
    const onDuplicated = vi.fn();
    const editing = {
        labels: [],
        editTransaction: null,
        duplicateTransaction: { id: 'a' } as DecryptedTransaction,
        setDuplicateTransaction: vi.fn(),
    } as unknown as TransactionRowEditing;

    dialogs.length = 0;
    render(
        <TransactionRowDialogs
            editing={editing}
            categories={[]}
            accounts={[]}
            banks={[]}
            automationRules={[]}
            onDuplicated={onDuplicated}
        />,
    );

    const duplicate = dialogs.find((props) => props.mode === 'create')!;

    return { duplicate, editing, onDuplicated };
}

describe('TransactionRowDialogs', () => {
    it('brings a duplicate in once its dialog closes, so "Save and add another" survives', () => {
        const { duplicate, onDuplicated } = renderDialogs();

        act(() => duplicate.onSuccess?.({} as DecryptedTransaction));
        expect(onDuplicated).not.toHaveBeenCalled();

        act(() => duplicate.onOpenChange(false));
        expect(onDuplicated).toHaveBeenCalledOnce();
    });

    it('fetches nothing when the duplicate dialog closes without a save', () => {
        const { duplicate, editing, onDuplicated } = renderDialogs();

        act(() => duplicate.onOpenChange(false));

        expect(editing.setDuplicateTransaction).toHaveBeenCalledWith(null);
        expect(onDuplicated).not.toHaveBeenCalled();
    });
});
