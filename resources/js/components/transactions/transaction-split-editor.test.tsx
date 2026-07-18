import {
    TransactionSplitEditor,
    validTransactionSplits,
} from '@/components/transactions/transaction-split-editor';
import type { Category } from '@/types/category';
import type { TransactionSplit } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/components/transactions/category-select', () => ({
    CategorySelect: ({
        value,
        onValueChange,
        categories,
        'data-testid': testId,
    }: {
        value: string;
        onValueChange: (value: string) => void;
        categories: Category[];
        'data-testid'?: string;
    }) => (
        <select
            aria-label={testId}
            value={value}
            onChange={(event) => onValueChange(event.target.value)}
        >
            <option value="null">Select</option>
            {categories.map((category: Category) => (
                <option key={category.id} value={category.id}>
                    {category.name}
                </option>
            ))}
        </select>
    ),
}));

vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: ({
        id,
        value,
        onChange,
    }: {
        id: string;
        value: number;
        onChange: (value: number) => void;
    }) => (
        <input
            id={id}
            aria-label={id}
            value={value}
            onChange={(event) => onChange(Number(event.target.value))}
        />
    ),
}));

const categories = [
    { id: 'food', name: 'Food', type: 'expense' },
    { id: 'home', name: 'Home', type: 'expense' },
    { id: 'salary', name: 'Salary', type: 'income' },
] as Category[];

const splits: TransactionSplit[] = [
    { category_id: 'food', amount: -6000, position: 0 },
    { category_id: 'home', amount: -3000, position: 1 },
];

describe('TransactionSplitEditor', () => {
    it('reports exact signed totals and rejects incomplete splits', () => {
        expect(validTransactionSplits(-10000, splits)).toBe(false);
        expect(
            validTransactionSplits(-10000, [
                splits[0],
                { ...splits[1], amount: -4000 },
            ]),
        ).toBe(true);
        expect(
            validTransactionSplits(2000, [
                { category_id: 'food', amount: 1200, position: 0 },
                { category_id: 'home', amount: 800, position: 1 },
            ]),
        ).toBe(true);
        expect(
            validTransactionSplits(-10000, [
                splits[0],
                { ...splits[1], amount: 4000 },
            ]),
        ).toBe(false);
    });

    it('uses the remaining amount and filters later categories by type', () => {
        const onChange = vi.fn();
        render(
            <TransactionSplitEditor
                amount={-10000}
                currencyCode="EUR"
                categories={categories}
                value={splits}
                onChange={onChange}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'Split amounts must equal',
        );
        expect(screen.queryByRole('option', { name: 'Salary' })).not.toBeNull();
        const secondSelect = screen.getByLabelText('split-category-1');
        expect(secondSelect.textContent).not.toContain('Salary');

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Use remaining' })[1],
        );
        expect(onChange).toHaveBeenCalledWith([
            splits[0],
            { ...splits[1], amount: -4000 },
        ]);
    });

    it('adds a line prefilled with the remaining amount', () => {
        const onChange = vi.fn();
        render(
            <TransactionSplitEditor
                amount={-10000}
                currencyCode="EUR"
                categories={categories}
                value={splits}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Add line' }));
        expect(onChange).toHaveBeenCalledWith([
            ...splits,
            { category_id: '', amount: -1000, position: 2 },
        ]);
    });

    it('updates amounts and rejects zero or missing-category lines', () => {
        const onChange = vi.fn();
        render(
            <TransactionSplitEditor
                amount={-10000}
                currencyCode="EUR"
                categories={categories}
                value={splits}
                onChange={onChange}
            />,
        );

        fireEvent.change(screen.getByLabelText('split-amount-1'), {
            target: { value: '-4000' },
        });
        expect(onChange).toHaveBeenCalledWith([
            splits[0],
            { ...splits[1], amount: -4000 },
        ]);

        expect(
            validTransactionSplits(-10000, [
                splits[0],
                { category_id: '', amount: -4000, position: 1 },
            ]),
        ).toBe(false);
        expect(
            validTransactionSplits(-10000, [
                splits[0],
                { ...splits[1], amount: 0 },
            ]),
        ).toBe(false);
    });
});
