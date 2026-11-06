import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';

/**
 * The labels a transaction shows, in the order it carries them, leaving out
 * one every row of the list already has and ids that no longer resolve.
 */
export function resolveTransactionLabels(
    transaction: DecryptedTransaction,
    labels: Label[],
    hiddenLabelId?: string,
): Label[] {
    return (transaction.label_ids || [])
        .filter((id) => id !== hiddenLabelId)
        .map((id) => labels.find((label) => label.id === id))
        .filter((label): label is Label => label !== undefined);
}
