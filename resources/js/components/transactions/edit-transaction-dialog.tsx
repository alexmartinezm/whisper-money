import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
import {
    TransactionSplitEditor,
    validTransactionSplits,
} from '@/components/transactions/transaction-split-editor';
import { AmountInput } from '@/components/ui/amount-input';
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
import { useSyncContext } from '@/contexts/sync-context';
import { useLocale } from '@/hooks/use-locale';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRulesForNewTransaction } from '@/lib/rule-engine';
import { appendNoteIfNotPresent } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
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
import { router } from '@inertiajs/react';
import axios from 'axios';
import { getYear, parseISO } from 'date-fns';
import { Trash2 } from 'lucide-react';
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
    const STORAGE_KEY_UPDATE_BALANCE =
        'whisper_money_update_balance_on_transaction';

    const { sync } = useSyncContext();
    const [transactionDate, setTransactionDate] = useState('');
    const [description, setDescription] = useState('');
    const [amount, setAmount] = useState<number>(0);
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
            const stored = localStorage.getItem(STORAGE_KEY_UPDATE_BALANCE);
            // Active by default; only an explicit opt-out turns it off.
            return stored === null ? true : stored === 'true';
        }
        return true;
    });

    // Manually created transactions can edit every field (account, date, amount,
    // description) both on creation and afterwards. Imported ones keep those locked.
    const canEditAllFields =
        mode === 'create' || transaction?.source === 'manually_created';

    useEffect(() => {
        if (mode === 'edit' && transaction) {
            setTransactionDate(transaction.transaction_date);
            setDescription(transaction.decryptedDescription);
            setAmount(transaction.amount);
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
        } else if (mode === 'create' && open) {
            const today = new Date().toISOString().split('T')[0];
            setTransactionDate(today);
            setDescription('');
            setAmount(0);
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
                amount: amount / 100,
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
        localStorage.setItem(STORAGE_KEY_UPDATE_BALANCE, String(checked));
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (canEditAllFields) {
            if (!description.trim()) {
                toast.error(__('Description is required'));
                return;
            }
            if (amount === 0) {
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

        if (!validTransactionSplits(amount, splits, categories)) {
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
                        amount: amount,
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

                if (canEditAllFields) {
                    if (
                        trimmedDescription !== transaction.decryptedDescription
                    ) {
                        updateData.description = trimmedDescription;
                        updateData.description_iv = null;
                        finalDecryptedDescription = trimmedDescription;
                    }
                    if (amount !== transaction.amount) {
                        updateData.amount = amount;
                    }
                    if (
                        transactionDate !==
                        transaction.transaction_date.split('T')[0]
                    ) {
                        updateData.transaction_date = transactionDate;
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

                const updatedRecord = await transactionSyncService.getById(
                    transaction.id,
                );
                const updatedCategory =
                    splits === null && selectedCategoryId
                        ? categories.find(
                              (category) => category.id === selectedCategoryId,
                          ) || null
                        : null;

                const selectedLabels = labels.filter((label) =>
                    selectedLabelIds.includes(label.id),
                );
                const savedSplits =
                    result.splits ??
                    (removeSplits ? [] : (transaction.splits ?? []));

                const updatedTransaction: DecryptedTransaction = {
                    ...transaction,
                    category_id: splits === null ? selectedCategoryId : null,
                    category: updatedCategory,
                    splits: savedSplits,
                    is_split: savedSplits.length > 0,
                    split_count: savedSplits.length,
                    decryptedDescription: finalDecryptedDescription,
                    description:
                        updateData.description ?? transaction.description,
                    description_iv:
                        updateData.description_iv ?? transaction.description_iv,
                    decryptedNotes: trimmedNotes || null,
                    notes: encryptedNotes,
                    notes_iv: notesIv,
                    label_ids: selectedLabelIds,
                    labels: selectedLabels,
                    updated_at:
                        updatedRecord?.updated_at ?? transaction.updated_at,
                    ...(canEditAllFields
                        ? {
                              amount,
                              transaction_date: transactionDate,
                              account_id: accountId,
                              currency_code: editedCurrencyCode,
                              account: editedAccount ?? transaction.account,
                              bank: editedAccount?.bank?.id
                                  ? banks.find(
                                        (b) => b.id === editedAccount.bank?.id,
                                    )
                                  : transaction.bank,
                          }
                        : {}),
                };

                toast.success(__('Transaction updated successfully'));
                onSuccess(updatedTransaction);

                if (result.learned_rule) {
                    // The correction already taught the system a forward rule, so
                    // confirm that and offer an instant undo — and skip the
                    // "Automatize" prompt, which would only offer to create a rule
                    // that now exists. Mirrors the transaction-table flow.
                    const ruleId = result.learned_rule.id;

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

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[525px]">
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
                        {canEditAllFields && (
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
                                            placeholder={__('Select account')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {transactionalAccounts.map(
                                            (account) => (
                                                <SelectItem
                                                    key={account.id}
                                                    value={String(account.id)}
                                                >
                                                    {decryptedAccountNames.get(
                                                        account.id,
                                                    ) || __('[Loading...]')}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="date"
                                className={
                                    canEditAllFields
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Date')}
                            </FormLabel>
                            {canEditAllFields ? (
                                <Input
                                    id="date"
                                    type="date"
                                    value={transactionDate}
                                    onChange={(e) =>
                                        setTransactionDate(e.target.value)
                                    }
                                    disabled={isSubmitting}
                                    required
                                />
                            ) : (
                                <div className="text-sm">
                                    {transaction &&
                                        (() => {
                                            const date = parseISO(
                                                transaction.transaction_date,
                                            );
                                            const currentYear = getYear(
                                                new Date(),
                                            );
                                            const transactionYear =
                                                getYear(date);
                                            const formatString =
                                                transactionYear === currentYear
                                                    ? 'MMMM d'
                                                    : 'MMMM d, yyyy';
                                            const formatted = formatDate(
                                                date,
                                                formatString,
                                                locale,
                                            );
                                            // Capitalize first letter
                                            return (
                                                formatted
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                formatted.slice(1)
                                            );
                                        })()}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="description"
                                className={
                                    canEditAllFields
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Description')}
                            </FormLabel>
                            {canEditAllFields ? (
                                <Textarea
                                    id="description"
                                    value={description}
                                    onChange={(e) =>
                                        setDescription(e.target.value)
                                    }
                                    placeholder={__('Transaction description')}
                                    disabled={isSubmitting}
                                    required
                                    rows={3}
                                />
                            ) : (
                                <div className="space-y-1.5">
                                    <Textarea
                                        id="description"
                                        value={
                                            transaction?.decryptedDescription ??
                                            ''
                                        }
                                        disabled
                                        className="bg-muted"
                                        rows={3}
                                    />

                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            'This transaction was imported from a\n                                        file. The description cannot be\n                                        modified.',
                                        )}
                                    </p>
                                </div>
                            )}
                        </div>

                        {mode === 'edit' &&
                            (transaction?.creditor_name ||
                                transaction?.debtor_name) && (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {transaction.creditor_name && (
                                        <div className="space-y-2">
                                            <FormLabel className="text-sm text-muted-foreground">
                                                {__('Creditor')}
                                            </FormLabel>
                                            <Input
                                                value={
                                                    transaction.creditor_name
                                                }
                                                disabled
                                                readOnly
                                                className="bg-muted"
                                            />
                                        </div>
                                    )}

                                    {transaction.debtor_name && (
                                        <div className="space-y-2">
                                            <FormLabel className="text-sm text-muted-foreground">
                                                {__('Debtor')}
                                            </FormLabel>
                                            <Input
                                                value={transaction.debtor_name}
                                                disabled
                                                readOnly
                                                className="bg-muted"
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                        <div className="space-y-2">
                            <FormLabel
                                htmlFor="amount"
                                className={
                                    canEditAllFields
                                        ? ''
                                        : 'text-sm text-muted-foreground'
                                }
                            >
                                {__('Amount')}
                            </FormLabel>
                            {canEditAllFields ? (
                                <>
                                    <AmountInput
                                        id="amount"
                                        value={amount}
                                        onChange={setAmount}
                                        currencyCode={
                                            selectedAccount?.currency_code ||
                                            'USD'
                                        }
                                        placeholder="25.00"
                                        disabled={isSubmitting}
                                        required
                                        allowNegative
                                    />

                                    {!selectedAccount?.banking_connection_id && (
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
                                </>
                            ) : (
                                <div className="text-sm font-medium">
                                    {transaction &&
                                        new Intl.NumberFormat(locale, {
                                            style: 'currency',
                                            currency: transaction.currency_code,
                                        })
                                            .format(transaction.amount / 100)
                                            .replace(/\s/g, '\u202F')}
                                </div>
                            )}
                        </div>

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
                                                amount,
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
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <TransactionSplitEditor
                                    amount={amount}
                                    currencyCode={
                                        selectedAccount?.currency_code ??
                                        transaction?.currency_code ??
                                        'USD'
                                    }
                                    categories={categories}
                                    value={splits}
                                    onChange={setSplits}
                                    disabled={isSubmitting}
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

                        <div className="space-y-2">
                            <FormLabel htmlFor="notes">{__('Notes')}</FormLabel>
                            <Textarea
                                id="notes"
                                placeholder={__('Add notes...')}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={3}
                                disabled={isSubmitting}
                            />
                        </div>
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
                                    amount,
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
