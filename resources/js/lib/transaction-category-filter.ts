import { getDescendantIds } from '@/lib/category-tree';
import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import { type UUID } from '@/types/uuid';

export const UNCATEGORIZED_CATEGORY_ID: UUID = 'uncategorized';

type CategorizedTransaction = Pick<Transaction, 'category_id' | 'splits'>;
type TransactionCategoryFilter = (
    transaction: CategorizedTransaction,
) => boolean;

/**
 * The categories a selection reaches, each picked one with its whole subtree.
 * The "uncategorized" pseudo id stands for no category, so it is not one.
 */
export function expandCategorySelection(
    categoryIds: UUID[],
    categories: Category[],
): Set<UUID> {
    const selectedCategoryIds = new Set<UUID>();

    for (const categoryId of categoryIds) {
        if (categoryId === UNCATEGORIZED_CATEGORY_ID) {
            continue;
        }

        selectedCategoryIds.add(categoryId);
        for (const descendantId of getDescendantIds(categoryId, categories)) {
            selectedCategoryIds.add(descendantId);
        }
    }

    return selectedCategoryIds;
}

export function createTransactionCategoryFilter(
    categoryIds: UUID[],
    categories: Category[],
): TransactionCategoryFilter {
    if (categoryIds.length === 0) {
        return () => true;
    }

    const selectedCategoryIds = expandCategorySelection(
        categoryIds,
        categories,
    );
    const uncategorizedSelected = categoryIds.includes(
        UNCATEGORIZED_CATEGORY_ID,
    );

    return (transaction) => {
        if (
            uncategorizedSelected &&
            transaction.category_id === null &&
            (transaction.splits?.length ?? 0) === 0
        ) {
            return true;
        }

        if (
            transaction.category_id !== null &&
            selectedCategoryIds.has(transaction.category_id)
        ) {
            return true;
        }

        return (
            transaction.splits?.some((split) =>
                selectedCategoryIds.has(split.category_id),
            ) ?? false
        );
    };
}

export function matchesTransactionCategoryFilter(
    transaction: CategorizedTransaction,
    categoryIds: UUID[],
    categories: Category[],
): boolean {
    if (categoryIds.length === 0) {
        return true;
    }

    return createTransactionCategoryFilter(
        categoryIds,
        categories,
    )(transaction);
}
