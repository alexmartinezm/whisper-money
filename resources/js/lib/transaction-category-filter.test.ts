import type { Category } from '@/types/category';
import type { Transaction } from '@/types/transaction';
import { describe, expect, it } from 'vitest';
import { matchesTransactionCategoryFilter } from './transaction-category-filter';

function category(id: string, parent_id: string | null = null): Category {
    return {
        id,
        name: id,
        icon: 'Receipt',
        color: 'gray',
        type: 'expense',
        cashflow_direction: 'hidden',
        parent_id,
    } as Category;
}

function transaction(
    category_id: string | null,
    splits?: Transaction['splits'],
): Pick<Transaction, 'category_id' | 'splits'> {
    return { category_id, splits };
}

describe('matchesTransactionCategoryFilter', () => {
    it('matches every transaction when no categories are selected', () => {
        expect(
            matchesTransactionCategoryFilter(transaction(null, []), [], []),
        ).toBe(true);
    });

    it('matches a split parent through one of its split categories', () => {
        const food = category('food');
        const home = category('home');

        expect(
            matchesTransactionCategoryFilter(
                transaction(null, [
                    {
                        id: 'split-food',
                        transaction_id: 'transaction',
                        category_id: food.id,
                        category: food,
                        amount: -6000,
                        position: 0,
                    },
                    {
                        id: 'split-home',
                        transaction_id: 'transaction',
                        category_id: home.id,
                        category: home,
                        amount: -4000,
                        position: 1,
                    },
                ]),
                [food.id],
                [food, home],
            ),
        ).toBe(true);
    });

    it('matches descendant split categories when a parent category is selected', () => {
        const food = category('food');
        const groceries = category('groceries', food.id);
        const transactionWithSplit = transaction(null, [
            {
                id: 'split-groceries',
                transaction_id: 'transaction',
                category_id: groceries.id,
                category: groceries,
                amount: -10000,
                position: 0,
            },
        ]);

        expect(
            matchesTransactionCategoryFilter(
                transactionWithSplit,
                [food.id],
                [food, groceries],
            ),
        ).toBe(true);
    });

    it('does not classify a split parent as uncategorized', () => {
        const food = category('food');

        expect(
            matchesTransactionCategoryFilter(
                transaction(null, [
                    {
                        id: 'split-food',
                        transaction_id: 'transaction',
                        category_id: food.id,
                        category: food,
                        amount: -10000,
                        position: 0,
                    },
                ]),
                ['uncategorized'],
                [food],
            ),
        ).toBe(false);
    });

    it('matches an ordinary uncategorized transaction', () => {
        expect(
            matchesTransactionCategoryFilter(
                transaction(null, []),
                ['uncategorized'],
                [],
            ),
        ).toBe(true);
    });
});
