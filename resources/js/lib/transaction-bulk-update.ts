import type {
    DecryptedTransaction,
    ServerTransaction,
} from '@/types/transaction';

export function mergeAuthoritativeTransactions(
    previous: DecryptedTransaction[],
    authoritative: ServerTransaction[],
): DecryptedTransaction[] {
    const byId = new Map(
        authoritative.map((transaction) => [transaction.id, transaction]),
    );

    return previous.map((transaction) => {
        const replacement = byId.get(transaction.id);
        if (!replacement) {
            return transaction;
        }

        return {
            ...transaction,
            ...replacement,
            decryptedDescription: transaction.decryptedDescription,
            decryptedNotes: transaction.decryptedNotes,
            bank: replacement.account?.bank ?? transaction.bank,
            labels: replacement.labels ?? [],
        };
    });
}
