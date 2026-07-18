import { CategorySelect } from '@/components/transactions/category-select';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { type Category } from '@/types/category';
import { type TransactionSplit } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo } from 'react';

interface TransactionSplitEditorProps {
    amount: number;
    currencyCode: string;
    categories: Category[];
    value: TransactionSplit[];
    onChange: (splits: TransactionSplit[]) => void;
    disabled?: boolean;
}

export function TransactionSplitEditor({
    amount,
    currencyCode,
    categories,
    value,
    onChange,
    disabled = false,
}: TransactionSplitEditorProps) {
    const splitCategories = useMemo(
        () =>
            categories.filter(
                (category) =>
                    category.type === 'expense' || category.type === 'income',
            ),
        [categories],
    );
    const total = value.reduce((sum, split) => sum + split.amount, 0);
    const remaining = amount - total;
    const firstCategory = splitCategories.find(
        (category) => category.id === value[0]?.category_id,
    );
    const compatibleCategories = useMemo(
        () =>
            firstCategory
                ? splitCategories.filter(
                      (category) => category.type === firstCategory.type,
                  )
                : splitCategories,
        [splitCategories, firstCategory],
    );

    function update(index: number, patch: Partial<TransactionSplit>) {
        const nextCategory =
            index === 0 && patch.category_id
                ? splitCategories.find(
                      (category) => category.id === patch.category_id,
                  )
                : null;

        onChange(
            value.map((split, splitIndex) => {
                if (splitIndex === index) {
                    return { ...split, ...patch, position: splitIndex };
                }

                const category = splitCategories.find(
                    (candidate) => candidate.id === split.category_id,
                );

                return {
                    ...split,
                    category_id:
                        nextCategory && category?.type !== nextCategory.type
                            ? ''
                            : split.category_id,
                    position: splitIndex,
                };
            }),
        );
    }

    function remove(index: number) {
        onChange(
            value
                .filter((_, splitIndex) => splitIndex !== index)
                .map((split, position) => ({ ...split, position })),
        );
    }

    function add() {
        onChange([
            ...value,
            {
                category_id: '',
                amount: remaining,
                position: value.length,
            },
        ]);
        requestAnimationFrame(() =>
            document
                .querySelector<HTMLElement>(
                    `[data-testid="split-category-${value.length}"]`,
                )
                ?.focus(),
        );
    }

    return (
        <fieldset
            className="space-y-3 rounded-md border p-3"
            disabled={disabled}
        >
            <legend className="px-1 text-sm font-medium">
                {__('Split transaction')}
            </legend>
            {value.map((split, index) => (
                <div
                    className="grid grid-cols-[minmax(0,1fr)_minmax(8rem,0.65fr)_auto] items-end gap-2"
                    key={split.id ?? index}
                >
                    <div className="space-y-1">
                        <label
                            className="text-xs"
                            htmlFor={`split-category-${index}`}
                        >
                            {__('Category')} {index + 1}
                        </label>
                        <CategorySelect
                            value={split.category_id || 'null'}
                            onValueChange={(categoryId) =>
                                update(index, {
                                    category_id:
                                        categoryId === 'null' ? '' : categoryId,
                                })
                            }
                            categories={
                                index === 0
                                    ? splitCategories
                                    : compatibleCategories
                            }
                            placeholder={__('Select category')}
                            showUncategorized={false}
                            triggerClassName="w-full"
                            data-testid={`split-category-${index}`}
                        />
                    </div>
                    <div className="space-y-1">
                        <label
                            className="text-xs"
                            htmlFor={`split-amount-${index}`}
                        >
                            {__('Amount')} {index + 1}
                        </label>
                        <AmountInput
                            id={`split-amount-${index}`}
                            value={split.amount}
                            onChange={(lineAmount) =>
                                update(index, { amount: lineAmount })
                            }
                            currencyCode={currencyCode}
                            allowNegative
                        />
                        {remaining !== 0 && (
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 text-xs"
                                onClick={() =>
                                    update(index, {
                                        amount: split.amount + remaining,
                                    })
                                }
                            >
                                {__('Use remaining')}
                            </Button>
                        )}
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={__('Remove split line :number', {
                            number: index + 1,
                        })}
                        onClick={() => remove(index)}
                        disabled={value.length <= 2}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ))}
            <div
                className="flex items-center justify-between gap-2 text-sm"
                aria-live="polite"
            >
                <span>
                    {__('Total')}: {(total / 100).toFixed(2)} ·{' '}
                    {__('Remaining')}: {(remaining / 100).toFixed(2)}
                </span>
                <Button type="button" variant="outline" size="sm" onClick={add}>
                    <Plus className="size-4" />
                    {__('Add line')}
                </Button>
            </div>
            {remaining !== 0 && (
                <p className="text-sm text-destructive" role="alert">
                    {__('Split amounts must equal the transaction amount.')}
                </p>
            )}
        </fieldset>
    );
}

export function validTransactionSplits(
    amount: number,
    splits: TransactionSplit[] | null,
    categories?: Category[],
): boolean {
    if (splits === null) return true;
    if (splits.length < 2) return false;
    if (splits.some((split) => !split.category_id || split.amount === 0))
        return false;
    if (splits.some((split) => Math.sign(split.amount) !== Math.sign(amount)))
        return false;
    if (categories) {
        const selected = splits.map((split) =>
            categories.find((category) => category.id === split.category_id),
        );
        const type = selected[0]?.type;

        if (
            !type ||
            (type !== 'expense' && type !== 'income') ||
            selected.some((category) => category?.type !== type)
        ) {
            return false;
        }
    }
    return splits.reduce((sum, split) => sum + split.amount, 0) === amount;
}
