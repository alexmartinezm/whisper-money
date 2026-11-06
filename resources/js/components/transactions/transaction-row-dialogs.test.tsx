import {
    type TransactionRowEditing,
    useTransactionRowEditing,
} from '@/hooks/use-transaction-row-editing';
import { captureEvent } from '@/lib/posthog';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { act, render } from '@testing-library/react';
import { type ComponentProps, useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { EditTransactionDialog } from './edit-transaction-dialog';
import { TransactionRowDialogs } from './transaction-row-dialogs';

type DialogProps = ComponentProps<typeof EditTransactionDialog>;

const dialogs = vi.hoisted(() => [] as DialogProps[]);
const automate = vi.hoisted(() => ({
    latest: null as { open: boolean; candidate: unknown } | null,
}));

vi.mock('./edit-transaction-dialog', () => ({
    EditTransactionDialog: (props: DialogProps) => {
        dialogs.push(props);

        return null;
    },
}));
vi.mock('@/components/automation-rules/automate-categorization-dialog', () => ({
    AutomateCategorizationDialog: (props: {
        open: boolean;
        candidate: unknown;
    }) => {
        automate.latest = props;

        return null;
    },
}));
vi.mock('@/components/automation-rules/post-save-apply-rule-prompt', () => ({
    PostSaveApplyRulePrompt: () => null,
}));
vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

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

const NO_LABELS: Label[] = [];

/** The dialogs wired to the real hook, with a button to open a row. */
function RowOpenForEditing({ row }: { row: DecryptedTransaction }) {
    const [, setRows] = useState([row]);
    const editing = useTransactionRowEditing({
        initialLabels: NO_LABELS,
        setRows,
    });

    return (
        <>
            <button onClick={() => editing.setEditTransaction(row)}>
                edit
            </button>
            <TransactionRowDialogs
                editing={editing}
                categories={[]}
                accounts={[]}
                banks={[]}
                automationRules={[]}
                onDuplicated={vi.fn()}
            />
        </>
    );
}

describe('the row actions from the edit dialog, as a phone reaches them', () => {
    const row = {
        id: 'tx-1',
        category: { id: 'food' },
    } as unknown as DecryptedTransaction;

    function latest(mode: DialogProps['mode']) {
        return dialogs.findLast((props) => props.mode === mode)!;
    }

    function openRow() {
        dialogs.length = 0;
        const { getByText } = render(<RowOpenForEditing row={row} />);

        act(() => getByText('edit').click());

        return latest('edit');
    }

    it('hands the edit dialog its handlers, and Duplicate opens the duplicate dialog', () => {
        const edit = openRow();

        act(() => {
            edit.onOpenChange(false);
            edit.rowActions?.onDuplicate(row);
        });

        expect(latest('edit').open).toBe(false);
        expect(latest('create')).toEqual(
            expect.objectContaining({ open: true, duplicateFrom: row }),
        );
    });

    it('opens the automate dialog from there, reporting where it came from', () => {
        const edit = openRow();

        act(() => {
            edit.onOpenChange(false);
            edit.rowActions?.onAutomate(row);
        });

        expect(automate.latest).toEqual(
            expect.objectContaining({
                open: true,
                candidate: { transaction: row, category: row.category },
            }),
        );
        expect(captureEvent).toHaveBeenCalledWith(
            'automation_rule_toast_automatize_clicked',
            { source: 'edit_dialog' },
        );
    });
});

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
