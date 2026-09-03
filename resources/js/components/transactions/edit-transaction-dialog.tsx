import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
import {
    TransactionSplitEditor,
    validTransactionSplits,
} from '@/components/transactions/transaction-split-editor';
import { AmountInput } from '@/components/ui/amount-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useSyncContext } from '@/contexts/sync-context';
import { useLocale } from '@/hooks/use-locale';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { appendNoteIfNotPresent } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type DecryptedTransaction,
    type SplitLineInput,
    type TransactionSplit,
} from '@/types/transaction';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { getYear, parseISO } from 'date-fns';
import {
    FileText,
    HelpCircle,
    Landmark,
    Lock,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

function splitLineSignature(
    splits: ReadonlyArray<
        Pick<TransactionSplit, 'category_id' | 'amount' | 'position'>
    >,
): string {
    return JSON.stringify(
        splits.map(({ category_id, amount, position }) => ({
            category_id,
            amount,
            position,
        })),
    );
}

export function haveSplitLinesChanged(
    persisted: ReadonlyArray<
        Pick<TransactionSplit, 'category_id' | 'amount' | 'position'>
    >,
    draft: readonly SplitLineInput[],
): boolean {
    return splitLineSignature(persisted) !== splitLineSignature(draft);
}

interface EditTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules?: AutomationRule[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'edit_transaction_modal',
    ) => void;
    onLabelCreated?: (label: Label) => void;
    onDelete?: (transaction: DecryptedTransaction) => void;
    mode: 'create' | 'edit';
    initialAccountId?: string | null;
}

export function EditTransactionDialog({
    transaction,
    categories,
    accounts,
    banks,
    labels,
    automationRules = [],
    open,
    onOpenChange,
    onSuccess,
    onCategorized,
    onLabelCreated,
    onDelete,
    mode,
    initialAccountId = null,
}: EditTransactionDialogProps) {
    const locale = useLocale();
    const userCurrencyCode =
        usePage<SharedData>().props.auth.user.currency_code;
    const STORAGE_KEY_UPDATE_BALANCE =
        'whisper_money_update_balance_on_transaction';

    const { sync } = useSyncContext();
    const transactionSplittingEnabled =
        usePage<SharedData>().props.features.transactionSplitting;
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [unsignedAmount, setUnsignedAmount] = useState<number>(0);
    const [transactionType, setTransactionType] = useState<
        'expense' | 'income'
    >('expense');
    const [showNotes, setShowNotes] = useState(false);
    const [accountId, setAccountId] = useState<string>('');
    const [categoryId, setCategoryId] = useState<string>('null');
    const [splits, setSplits] = useState<SplitLineInput[] | null>(null);
    const [removeSplits, setRemoveSplits] = useState(false);
    const [selectedLabelIds, setSelectedLabelIds] = useState<string[]>([]);
    const [notes, setNotes] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [decryptedAccountNames, setDecryptedAccountNames] = useState<
        Map<string, string>
    >(new Map());
    const [updateAccountBalance, setUpdateAccountBalance] = useState(() => {
        if (typeof window !== 'undefined') {
            const stored = readStoredValue(STORAGE_KEY_UPDATE_BALANCE);
            // Active by default; only an explicit opt-out turns it off.
            return stored === null ? true : stored === 'true';
        }
        return true;
    });

    // Manually created transactions can edit account, amount and description
    // both on creation and afterwards. Bank-synced and imported ones keep those
    // locked to what the source reported.
    const canEditAllFields =
        mode === 'create' || transaction?.source === 'manually_created';

    // The toggle carries the sign, so the amount input never sees a minus and
    // nobody has to type one. Everything that persists or matches on the amount
    // reads this, never the unsigned field.
    const signedAmount =
        transactionType === 'income' ? unsignedAmount : -unsignedAmount;

    // The date is the exception, and is editable on every transaction: which
    // month one counts towards is the user's call, not the bank's — a payroll
    // booked on the 27th can belong to next month's budget. The server keeps
    // the date the source gave it in source_date, so the sync watermark does
    // not advance with the move.
    //
    // Only rows the user did not type in have a source to compare against: on a
    // manual transaction the user picked every date it ever had. The comparison
    // is against the field rather than the saved row, so the hint shows the
    // moment a different date is typed, and moving it back hides it again.
    const sourceDate =
        transaction && transaction.source !== 'manually_created'
            ? (transaction.source_date ??
              transaction.transaction_date.split('T')[0])
            : null;
    const movedFromSourceDate =
        sourceDate && sourceDate !== transactionDate
            ? formatDate(
                  parseISO(sourceDate),
                  getYear(parseISO(sourceDate)) === getYear(new Date())
                      ? 'MMMM d'
                      : 'MMMM d, yyyy',
                  locale,
              )
            : null;

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setUnsignedAmount(Math.abs(transaction.amount));
            setTransactionType(transaction.amount > 0 ? 'income' : 'expense');
            setAccountId(transaction.account_id);
            setCategoryId(transaction.category_id || 'null');
            setSplits(
                transaction.splits?.length
                    ? transaction.splits.map((split, position) => ({
                          category_id: split.category_id,
                          amount: split.amount,
                          position,
                      }))
                    : null,
            );
            setRemoveSplits(false);
            setSelectedLabelIds(
                transaction.label_ids ||
                    transaction.labels?.map((l) => l.id) ||
                    [],
            );
            setNotes(transaction.decryptedNotes || '');
            setShowNotes(!!transaction.decryptedNotes);
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setUnsignedAmount(0);
            setTransactionType('expense');
            setShowNotes(false);
            const availableAccounts = filterTransactionalAccounts(accounts);
            const initialAccount = availableAccounts.find(
                (account) => account.id === initialAccountId,
            );
            setAccountId(initialAccount?.id ?? '');
            setCategoryId('null');
            setSplits(null);
            setRemoveSplits(false);
            setSelectedLabelIds([]);
            setNotes('');
        }
    }, [mode, transaction, open, accounts, initialAccountId]);

    useEffect(() => {
        if (!open || !canEditAllFields) return;

        async function decryptAccountNames() {
            const keyString = getStoredKey();

            try {
                let key: CryptoKey | null = null;
                if (keyString) {
                    key = await importKey(keyString);
                }

                const decryptedNames = new Map<string, string>();

                await Promise.all(
                    accounts.map(async (account) => {
                        if (!account.encrypted) {
                            decryptedNames.set(account.id, account.name);
                            return;
                        }

                        if (!key || !account.name_iv) {
                            decryptedNames.set(account.id, '[Encrypted]');
                            return;
                        }

                        try {
                            const decryptedName = await decrypt(
                                account.name,
                                key,
                                account.name_iv,
                            );
                            decryptedNames.set(account.id, decryptedName);
                        } catch (error) {
                            console.error(
                                'Failed to decrypt account name:',
                                account.id,
                                error,
                            );
                            decryptedNames.set(account.id, '[Encrypted]');
                        }
                    }),
                );

                setDecryptedAccountNames(decryptedNames);
            } catch (error) {
                console.error('Failed to decrypt account names:', error);
            }
        }

        decryptAccountNames();
    }, [open, canEditAllFields, accounts]);

    async function checkAndApplyAutomationRules() {
        if (mode !== 'create' || automationRules.length === 0) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        const keyString = getStoredKey();
        if (!keyString) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        const key = await importKey(keyString);

        const result = await evaluateRulesForNewTransaction(
            {
                description: description.trim(),
                amount: signedAmount / 100,
                transaction_date: transactionDate,
                account_id: accountId,
                notes: notes.trim() || undefined,
            },
            automationRules,
            categories,
            accounts,
            banks,
            key,
        );

        if (!result) {
            return {
                categoryId: null,
                labelIds: [] as string[],
                matchedLabels: [] as Label[],
                notes: null,
                notesIv: null,
                ruleName: null,
            };
        }

        let finalNotes = notes.trim();
        const finalNotesIv = null;

        if (result.note && result.noteIv) {
            const decryptedRuleNote = await decrypt(
                result.note,
                key,
                result.noteIv,
            );

            finalNotes = appendNoteIfNotPresent(
                finalNotes || undefined,
                decryptedRuleNote,
            );
        }

        return {
            categoryId: result.categoryId,
            labelIds: result.labelIds || [],
            matchedLabels: result.labels || [],
            notes: finalNotes || null,
            notesIv: finalNotesIv,
            ruleName: result.rule.title,
        };
    }

    function handleUpdateBalanceChange(checked: boolean) {
        setUpdateAccountBalance(checked);
        writeStoredValue(STORAGE_KEY_UPDATE_BALANCE, String(checked));
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (canEditAllFields) {
            if (!description.trim()) {
                toast.error(__('Description is required'));
                return;
            }
            if (unsignedAmount === 0) {
                toast.error(__('Amount is required'));
                return;
            }
            if (!accountId) {
                toast.error(__('Account is required'));
                return;
            }
            if (!transactionDate) {
                toast.error(__('Date is required'));
                return;
            }
        }

        if (!validTransactionSplits(signedAmount, splits, categories)) {
            toast.error(__('Complete the split before saving'));
            return;
        }

        setIsSubmitting(true);
        try {
            const trimmedDescription = description.trim();

            if (mode === 'create') {
                const ruleResult = await checkAndApplyAutomationRules();

                let finalCategoryId =
                    splits === null && categoryId !== 'null'
                        ? categoryId
                        : null;
                let finalNotes = notes.trim();
                let finalLabelIds = [...selectedLabelIds];

                if (
                    splits === null &&
                    ruleResult.categoryId &&
                    !finalCategoryId
                ) {
                    finalCategoryId = ruleResult.categoryId;
                }
                if (ruleResult.notes) {
                    finalNotes = ruleResult.notes;
                }
                if (
                    ruleResult.labelIds.length > 0 &&
                    finalLabelIds.length === 0
                ) {
                    finalLabelIds = [...ruleResult.labelIds];
                }

                const finalDescription = trimmedDescription;
                const finalDescriptionIv = null;
                const encryptedNotes = finalNotes || null;
                const notesIv = null;

                const selectedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                if (!selectedAccount) {
                    throw new Error(__('Selected account not found'));
                }

                const createdTransaction = await transactionSyncService.create(
                    {
                        user_id: '00000000-0000-0000-0000-000000000000',
                        account_id: accountId,
                        category_id: finalCategoryId,
                        description: finalDescription,
                        description_iv: finalDescriptionIv,
                        transaction_date: transactionDate,
                        amount: signedAmount,
                        currency_code: selectedAccount.currency_code,
                        notes: encryptedNotes,
                        notes_iv: notesIv,
                        creditor_name: null,
                        debtor_name: null,
                        source: 'manually_created' as const,
                        label_ids:
                            finalLabelIds.length > 0
                                ? finalLabelIds
                                : undefined,
                        splits: splits ?? undefined,
                    },
                    {
                        updateBalance: selectedAccount.banking_connection_id
                            ? false
                            : updateAccountBalance,
                    },
                );

                const updatedCategory = createdTransaction.category_id
                    ? categories.find(
                          (category) =>
                              category.id === createdTransaction.category_id,
                      ) || null
                    : null;

                const transactionLabels = labels.filter((l) =>
                    finalLabelIds.includes(l.id),
                );

                const newTransaction: DecryptedTransaction = {
                    ...createdTransaction,
                    decryptedDescription: trimmedDescription,
                    decryptedNotes: finalNotes || null,
                    category: updatedCategory,
                    account: selectedAccount,
                    bank: selectedAccount.bank?.id
                        ? banks.find((b) => b.id === selectedAccount.bank?.id)
                        : undefined,
                    labels: transactionLabels,
                    label_ids: finalLabelIds,
                };

                toast.success(__('Transaction created successfully'));
                if (ruleResult.ruleName) {
                    toast.success(
                        __('Rule ":rule" applied', {
                            rule: ruleResult.ruleName,
                        }),
                    );
                }

                onSuccess(newTransaction);
                onOpenChange(false);

                // Sync to update IndexedDB
                sync();
            } else {
                if (!transaction) {
                    return;
                }

                const selectedCategoryId =
                    categoryId === 'null' ? null : categoryId;
                const trimmedNotes = notes.trim();
                const trimmedDescription = description.trim();

                let encryptedNotes: string | null = null;
                let notesIv: string | null = null;

                encryptedNotes = trimmedNotes || null;
                notesIv = null;

                const updateData: {
                    category_id?: string | null;
                    notes: string | null;
                    notes_iv: string | null;
                    description?: string;
                    description_iv?: string | null;
                    label_ids?: string[];
                    amount?: number;
                    transaction_date?: string;
                    account_id?: string;
                    currency_code?: string;
                    splits?: SplitLineInput[];
                } = {
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                    ...(splits === null &&
                    (removeSplits ||
                        selectedCategoryId !== transaction.category_id)
                        ? { category_id: selectedCategoryId }
                        : {}),
                    ...(splits !== null &&
                    haveSplitLinesChanged(transaction.splits ?? [], splits)
                        ? { splits }
                        : removeSplits
                          ? { splits: [] }
                          : {}),
                };

                let finalDecryptedDescription =
                    transaction.decryptedDescription;

                const editedAccount = accounts.find(
                    (acc) => acc.id === accountId,
                );
                const editedCurrencyCode =
                    editedAccount?.currency_code ?? transaction.currency_code;

                if (
                    transactionDate !==
                    transaction.transaction_date.split('T')[0]
                ) {
                    updateData.transaction_date = transactionDate;
                }

                if (canEditAllFields) {
                    if (
                        trimmedDescription !== transaction.decryptedDescription
                    ) {
                        updateData.description = trimmedDescription;
                        updateData.description_iv = null;
                        finalDecryptedDescription = trimmedDescription;
                    }
                    if (signedAmount !== transaction.amount) {
                        updateData.amount = signedAmount;
                    }
                    if (accountId !== transaction.account_id) {
                        updateData.account_id = accountId;
                        updateData.currency_code = editedCurrencyCode;
                    }
                }

                const result = await transactionSyncService.update(
                    transaction.id,
                    updateData,
                    {
                        // Gate on the transaction being manual, not on the target
                        // account: the backend adjuster skips connected accounts
                        // per-account, so this still reverses the old manual
                        // account when the edit moves it onto a connected one.
                        updateBalance: canEditAllFields
                            ? updateAccountBalance
                            : false,
                    },
                );

                const { learned_rule: learnedRule, ...serverTransaction } =
                    result;
                const savedSplits = serverTransaction.splits ?? [];
                const authoritativeCategoryId =
                    serverTransaction.category_id ?? null;
                const updatedCategory =
                    savedSplits.length === 0 && authoritativeCategoryId
                        ? categories.find(
                              (category) =>
                                  category.id === authoritativeCategoryId,
                          ) || null
                        : null;
                const authoritativeLabelIds = serverTransaction.label_ids ?? [];
                const selectedLabels = labels.filter((label) =>
                    authoritativeLabelIds.includes(label.id),
                );
                const authoritativeAccount = accounts.find(
                    (candidate) =>
                        candidate.id === serverTransaction.account_id,
                );

                const updatedTransaction: DecryptedTransaction = {
                    ...transaction,
                    ...serverTransaction,
                    category: updatedCategory,
                    label_ids: authoritativeLabelIds,
                    splits: savedSplits,
                    is_split: savedSplits.length > 0,
                    split_count: savedSplits.length,
                    decryptedDescription: finalDecryptedDescription,
                    decryptedNotes: trimmedNotes || null,
                    labels: selectedLabels,
                    account: authoritativeAccount ?? transaction.account,
                    bank: authoritativeAccount?.bank?.id
                        ? banks.find(
                              (bank) =>
                                  bank.id === authoritativeAccount.bank?.id,
                          )
                        : transaction.bank,
                };

                toast.success(__('Transaction updated successfully'));
                onSuccess(updatedTransaction);

                if (learnedRule) {
                    // The correction already taught the system a forward rule, so
                    // confirm that and offer an instant undo — and skip the
                    // "Automatize" prompt, which would only offer to create a rule
                    // that now exists. Mirrors the transaction-table flow.
                    const ruleId = learnedRule.id;

                    toast.success(
                        __(
                            'Learned: similar transactions will be categorized automatically.',
                        ),
                        {
                            closeButton: true,
                            duration: 10000,
                            action: {
                                label: __('Undo'),
                                onClick: () => {
                                    router.delete(destroy(ruleId).url, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    });
                                },
                            },
                        },
                    );
                } else if (
                    selectedCategoryId &&
                    selectedCategoryId !== transaction.category_id &&
                    updatedCategory
                ) {
                    onCategorized?.(
                        updatedTransaction,
                        updatedCategory,
                        'edit_transaction_modal',
                    );
                }
                onOpenChange(false);

                // Sync to update IndexedDB
                sync();
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            const validationMessage = axios.isAxiosError(error)
                ? Object.values(error.response?.data?.errors ?? {})
                      .flat()
                      .find((message): message is string =>
                          Boolean(message && typeof message === 'string'),
                      )
                : null;
            toast.error(
                validationMessage ??
                    (mode === 'create'
                        ? __('Failed to create transaction')
                        : __('Failed to update transaction')),
            );
        } finally {
            setIsSubmitting(false);
        }
    }

    const selectedAccount = accounts.find((acc) => acc.id === accountId);
    const transactionalAccounts = filterTransactionalAccounts(accounts);
    // An archived account stays selectable while editing a transaction that
    // already sits on it, otherwise the field reads as empty and the user cannot
    // fill it back in.
    const accountOptions =
        selectedAccount?.archived_at &&
        !transactionalAccounts.some((account) => account.id === accountId)
            ? [...transactionalAccounts, selectedAccount]
            : transactionalAccounts;

    const accountName = transaction
        ? decryptedAccountNames.get(transaction.account_id)
        : undefined;

    const headerCategory =
        categoryId !== 'null'
            ? (categories.find((category) => category.id === categoryId) ??
              null)
            : null;

    const formattedAmount = transaction
        ? new Intl.NumberFormat(locale, {
              style: 'currency',
              currency: transaction.currency_code,
          })
              .format(transaction.amount / 100)
              .replace(/\s/g, '\u202F')
        : '';

    // What the source reported and the user cannot change. The date is absent
    // on purpose: this fork lets every transaction be moved, so it stays an
    // editable field below rather than a locked row here.
    const detailRows = transaction
        ? [
              transaction.creditor_name
                  ? { label: __('Creditor'), value: transaction.creditor_name }
                  : null,
              transaction.debtor_name
                  ? { label: __('Debtor'), value: transaction.debtor_name }
                  : null,
              accountName
                  ? {
                        label: __('Account'),
                        value: transaction.bank?.name
                            ? `${accountName} · ${transaction.bank.name}`
                            : accountName,
                    }
                  : null,
          ].filter(
              (row): row is { label: string; value: string } => row !== null,
          )
        : [];

    const sourceLabel =
        transaction?.source === 'imported'
            ? __('Imported from a file')
            : __('Imported from your bank');
    const SourceIcon = transaction?.source === 'imported' ? FileText : Landmark;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-[525px]"
                tabIndex={!canEditAllFields ? -1 : undefined}
                onOpenAutoFocus={
                    !canEditAllFields
                        ? (event) => {
                              event.preventDefault();
                              (event.currentTarget as HTMLElement).focus();
                          }
                        : undefined
                }
            >
                <DialogHeader>
                    <DialogTitle>
                        {mode === 'create'
                            ? __('Add Transaction')
                            : __('Edit Transaction')}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'create'
                            ? __('Create a new transaction.')
                            : canEditAllFields
                              ? __('Update this transaction.')
                              : __(
                                    'Update the category and notes for this transaction.',
                                )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="space-y-4 py-4">
                        {canEditAllFields ? (
                            <>
                                <div className="space-y-2">
                                    <FormLabel htmlFor="amount">
                                        {__('Amount')}
                                    </FormLabel>
                                    <div className="flex items-stretch gap-3">
                                        <ToggleGroup
                                            type="single"
                                            variant="outline"
                                            value={transactionType}
                                            onValueChange={(value) => {
                                                if (value) {
                                                    setTransactionType(
                                                        value as
                                                            | 'expense'
                                                            | 'income',
                                                    );
                                                }
                                            }}
                                            disabled={isSubmitting}
                                        >
                                            <ToggleGroupItem
                                                value="expense"
                                                className="h-11 px-4"
                                                data-testid="transaction-type-expense"
                                            >
                                                {__('Expense')}
                                            </ToggleGroupItem>
                                            <ToggleGroupItem
                                                value="income"
                                                className="h-11 px-4"
                                                data-testid="transaction-type-income"
                                            >
                                                {__('Income')}
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                        <div className="flex-1">
                                            <AmountInput
                                                id="amount"
                                                value={unsignedAmount}
                                                // A typed minus sign still parses negative even
                                                // without allowNegative; the toggle owns the sign.
                                                onChange={(cents) =>
                                                    setUnsignedAmount(
                                                        Math.abs(cents),
                                                    )
                                                }
                                                currencyCode={
                                                    selectedAccount?.currency_code ||
                                                    userCurrencyCode
                                                }
                                                placeholder="25.00"
                                                disabled={isSubmitting}
                                                required
                                                className="h-11 text-right text-xl font-semibold tabular-nums md:text-xl"
                                            />
                                        </div>
                                    </div>

                                    {selectedAccount?.banking_connection_id ? (
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                "This account's balance comes from your bank, so it won't change.",
                                            )}
                                        </p>
                                    ) : (
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="update-balance"
                                                checked={updateAccountBalance}
                                                onCheckedChange={(checked) =>
                                                    handleUpdateBalanceChange(
                                                        checked === true,
                                                    )
                                                }
                                                disabled={isSubmitting}
                                            />

                                            <FormLabel
                                                htmlFor="update-balance"
                                                className="cursor-pointer font-normal"
                                            >
                                                {__('Update account balance')}
                                            </FormLabel>
                                        </div>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <FormLabel htmlFor="description">
                                        {__('Description')}
                                    </FormLabel>
                                    <Textarea
                                        id="description"
                                        value={description}
                                        onChange={(e) =>
                                            setDescription(e.target.value)
                                        }
                                        placeholder={__(
                                            'Transaction description',
                                        )}
                                        disabled={isSubmitting}
                                        required
                                        rows={3}
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <FormLabel htmlFor="date">
                                            {__('Date')}
                                        </FormLabel>
                                        <Input
                                            id="date"
                                            type="date"
                                            value={transactionDate}
                                            onChange={(e) =>
                                                setTransactionDate(
                                                    e.target.value,
                                                )
                                            }
                                            disabled={isSubmitting}
                                            required
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                'The date decides which month and budget this transaction counts towards.',
                                            )}
                                        </p>
                                        {movedFromSourceDate && (
                                            <p
                                                data-testid="original-transaction-date"
                                                className="text-sm text-muted-foreground"
                                            >
                                                {__('Original date: :date', {
                                                    date: movedFromSourceDate,
                                                })}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <FormLabel htmlFor="account">
                                            {__('Account')}
                                        </FormLabel>
                                        <Select
                                            value={accountId}
                                            onValueChange={setAccountId}
                                            disabled={isSubmitting}
                                        >
                                            <SelectTrigger
                                                id="account"
                                                data-testid="account-select"
                                            >
                                                <SelectValue
                                                    placeholder={__(
                                                        'Select account',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {accountOptions.map(
                                                    (account) => (
                                                        <SelectItem
                                                            key={account.id}
                                                            value={String(
                                                                account.id,
                                                            )}
                                                        >
                                                            {`${decryptedAccountNames.get(account.id) || __('[Loading...]')} · ${account.currency_code}`}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </>
                        ) : (
                            transaction && (
                                <>
                                    <div className="flex items-center gap-3">
                                        {headerCategory ? (
                                            <CategoryIcon
                                                category={headerCategory}
                                                className="p-2.5"
                                                iconClassName="size-5 sm:size-5"
                                            />
                                        ) : (
                                            <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                <HelpCircle className="size-4 text-zinc-500" />
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">
                                                {description}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {accountName ?? ''}
                                            </div>
                                        </div>
                                        <div className="shrink-0 text-2xl font-semibold tabular-nums">
                                            {formattedAmount}
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <div className="rounded-md border">
                                            {detailRows.map((row) => (
                                                <div
                                                    key={row.label}
                                                    className="flex items-center justify-between gap-4 border-b px-3 py-2.5 text-sm"
                                                >
                                                    <span className="text-muted-foreground">
                                                        {row.label}
                                                    </span>
                                                    <span className="truncate text-right">
                                                        {row.value}
                                                    </span>
                                                </div>
                                            ))}
                                            <div className="flex items-center justify-between gap-4 px-3 py-2.5 text-sm">
                                                <span className="text-muted-foreground">
                                                    {__('Source')}
                                                </span>
                                                <Badge variant="secondary">
                                                    <SourceIcon />
                                                    {sourceLabel}
                                                </Badge>
                                            </div>
                                        </div>
                                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <Lock className="size-3" />
                                            {__(
                                                'These details cannot be edited.',
                                            )}
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <FormLabel htmlFor="date">
                                            {__('Date')}
                                        </FormLabel>
                                        <Input
                                            id="date"
                                            type="date"
                                            value={transactionDate}
                                            onChange={(e) =>
                                                setTransactionDate(
                                                    e.target.value,
                                                )
                                            }
                                            disabled={isSubmitting}
                                            required
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                'The date decides which month and budget this transaction counts towards.',
                                            )}
                                        </p>
                                        {movedFromSourceDate && (
                                            <p
                                                data-testid="original-transaction-date"
                                                className="text-sm text-muted-foreground"
                                            >
                                                {__('Original date: :date', {
                                                    date: movedFromSourceDate,
                                                })}
                                            </p>
                                        )}
                                    </div>
                                </>
                            )
                        )}

                        {splits === null ? (
                            <div className="space-y-2">
                                <FormLabel htmlFor="category">
                                    {__('Category')}
                                </FormLabel>
                                <CategorySelect
                                    value={categoryId}
                                    onValueChange={setCategoryId}
                                    categories={categories}
                                    disabled={isSubmitting}
                                    placeholder={__('Uncategorized')}
                                    triggerClassName="w-full"
                                    showUncategorized={true}
                                    data-testid="category-select"
                                />
                                {transactionSplittingEnabled && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setRemoveSplits(false);
                                            setSplits([
                                                {
                                                    category_id:
                                                        categoryId === 'null'
                                                            ? ''
                                                            : categoryId,
                                                    amount: signedAmount,
                                                    position: 0,
                                                },
                                                {
                                                    category_id: '',
                                                    amount: 0,
                                                    position: 1,
                                                },
                                            ]);
                                        }}
                                    >
                                        {__('Split transaction')}
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <TransactionSplitEditor
                                    amount={signedAmount}
                                    currencyCode={
                                        selectedAccount?.currency_code ??
                                        transaction?.currency_code ??
                                        userCurrencyCode
                                    }
                                    categories={categories}
                                    value={splits}
                                    onChange={setSplits}
                                    disabled={
                                        isSubmitting ||
                                        !transactionSplittingEnabled
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                __('Remove split transaction?'),
                                            )
                                        ) {
                                            setCategoryId(
                                                splits[0]?.category_id ||
                                                    'null',
                                            );
                                            setSplits(null);
                                            setRemoveSplits(true);
                                        }
                                    }}
                                >
                                    {__('Remove split')}
                                </Button>
                            </div>
                        )}

                        <div className="space-y-2">
                            <FormLabel>{__('Labels')}</FormLabel>
                            <LabelCombobox
                                value={selectedLabelIds}
                                onValueChange={setSelectedLabelIds}
                                labels={labels}
                                disabled={isSubmitting}
                                placeholder={__('Add labels...')}
                                allowCreate={true}
                                onLabelCreated={onLabelCreated}
                            />
                        </div>

                        {showNotes ? (
                            <div className="space-y-2">
                                <FormLabel htmlFor="notes">
                                    {__('Notes')}
                                </FormLabel>
                                <Textarea
                                    id="notes"
                                    placeholder={__('Add notes...')}
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    rows={3}
                                    disabled={isSubmitting}
                                />
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="-ml-2 w-fit px-2 text-muted-foreground"
                                onClick={() => setShowNotes(true)}
                                disabled={isSubmitting}
                            >
                                <Plus />
                                {__('Add note')}
                            </Button>
                        )}
                    </div>

                    <DialogFooter>
                        {mode === 'edit' && onDelete && transaction && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    onOpenChange(false);
                                    onDelete(transaction);
                                }}
                                disabled={isSubmitting}
                                className="text-destructive hover:bg-destructive/10 hover:text-destructive sm:mr-auto dark:hover:bg-destructive/20"
                            >
                                <Trash2 />
                                {__('Delete')}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                !validTransactionSplits(
                                    signedAmount,
                                    splits,
                                    categories,
                                )
                            }
                            data-testid="submit-transaction"
                        >
                            {isSubmitting
                                ? __('Saving...')
                                : mode === 'create'
                                  ? __('Create Transaction')
                                  : __('Save Changes')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
