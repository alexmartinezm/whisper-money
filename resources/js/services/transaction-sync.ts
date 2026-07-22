import { db, withDb } from '@/lib/dexie-db';
import { TransactionSyncManager } from '@/lib/sync-manager';
import type { LearnedRuleNotice } from '@/types/automation-rule';
import type {
    ServerTransaction,
    SplitLineInput,
    Transaction,
} from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import axios from 'axios';
import { format } from 'date-fns';

/** A transaction update plus any rule the correction just taught the system. */
export type UpdatedTransaction = ServerTransaction & {
    learned_rule?: LearnedRuleNotice | null;
};

export interface TransactionUpdateData extends Omit<
    Partial<Transaction>,
    'splits'
> {
    label_ids?: string[];
    splits?: SplitLineInput[];
}

export type TransactionCreateData = Omit<
    Transaction,
    'id' | 'created_at' | 'updated_at' | 'splits'
> & {
    splits?: SplitLineInput[];
};

export interface BulkUpdateResult {
    updated_count: number;
    skipped_split_count: number;
    updated_ids: string[];
    skipped_split_ids: string[];
    transactions: ServerTransaction[];
}

interface TransactionFilters {
    dateFrom?: Date | null;
    dateTo?: Date | null;
    amountMin?: number | null;
    amountMax?: number | null;
    categoryIds?: UUID[];
    accountIds?: string[];
    labelIds?: string[];
    creditorName?: string;
    debtorName?: string;
    searchText?: string;
    aiCategorizedOnly?: boolean;
}

export function transformTransactionFromServer(
    data: Record<string, unknown>,
): Record<string, unknown> {
    const serverLabels = data.labels as unknown;
    const label_ids = Array.isArray(serverLabels)
        ? serverLabels.map((label) => String((label as { id: unknown }).id))
        : [];
    // Relations other than labels (including embedded splits) remain intact.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { labels, ...rest } = data;

    return {
        ...rest,
        transaction_date: String(data.transaction_date).slice(0, 10),
        label_ids,
    };
}

class TransactionSyncService {
    private syncManager: TransactionSyncManager;

    constructor() {
        this.syncManager = new TransactionSyncManager({
            endpoint: '/api/sync/transactions',
            transformFromServer: transformTransactionFromServer,
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Transaction[]> {
        return await this.syncManager.getAll();
    }

    async getById(id: UUID): Promise<Transaction | null> {
        return await this.syncManager.getById(id);
    }

    async getByAccountId(accountId: UUID): Promise<Transaction[]> {
        return await this.syncManager.getByAccountId(accountId);
    }

    async create(
        data: TransactionCreateData,
        options?: { updateBalance?: boolean },
    ): Promise<Transaction> {
        const response = await axios.post('/transactions', {
            ...data,
            ...(options?.updateBalance ? { update_balance: true } : {}),
        });
        const serverData = response.data.data || response.data;

        const label_ids = serverData.labels?.map((l: { id: string }) => l.id);
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels, ...rest } = serverData;

        return {
            ...rest,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: label_ids || [],
        } as Transaction;
    }

    async createMany(
        transactions: TransactionCreateData[],
    ): Promise<Transaction[]> {
        const created: Transaction[] = [];

        for (const data of transactions) {
            const transaction = await this.create(data);
            created.push(transaction);
        }

        return created;
    }

    async update(
        id: string,
        data: TransactionUpdateData,
        options?: { updateBalance?: boolean },
    ): Promise<UpdatedTransaction> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch(`/transactions/${id}`, {
            ...transactionData,
            label_ids,
            ...(options?.updateBalance ? { update_balance: true } : {}),
        });

        const serverData = response.data.data || response.data;

        const serverLabelIds = serverData.labels?.map(
            (l: { id: string }) => l.id,
        );
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels: _labels, ...restServerData } = serverData;

        const updatedTransaction = {
            ...restServerData,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: serverLabelIds || [],
        } as Transaction;

        await withDb<void>(async () => {
            await db.transactions.put(updatedTransaction);
        }, undefined);

        return {
            ...updatedTransaction,
            labels: Array.isArray(serverData.labels) ? serverData.labels : [],
            learned_rule: response.data.learned_rule ?? null,
        } as UpdatedTransaction;
    }

    async updateMany(
        ids: string[],
        data: TransactionUpdateData,
    ): Promise<BulkUpdateResult> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch('/transactions/bulk', {
            transaction_ids: ids,
            label_ids: label_ids,
            ...transactionData,
        });

        return await this.persistBulkResult(response.data);
    }

    async updateByFilters(
        filters: TransactionFilters,
        data: TransactionUpdateData,
    ): Promise<BulkUpdateResult> {
        const { label_ids, ...transactionData } = data;

        const requestFilters: Record<string, unknown> = {};
        if (filters.dateFrom) {
            requestFilters.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
        }
        if (filters.dateTo) {
            requestFilters.date_to = format(filters.dateTo, 'yyyy-MM-dd');
        }
        if (filters.amountMin !== null && filters.amountMin !== undefined) {
            requestFilters.amount_min = filters.amountMin;
        }
        if (filters.amountMax !== null && filters.amountMax !== undefined) {
            requestFilters.amount_max = filters.amountMax;
        }
        if (filters.categoryIds && filters.categoryIds.length > 0) {
            requestFilters.category_ids = filters.categoryIds;
        }
        if (filters.accountIds && filters.accountIds.length > 0) {
            requestFilters.account_ids = filters.accountIds;
        }
        if (filters.labelIds && filters.labelIds.length > 0) {
            requestFilters.label_ids = filters.labelIds;
        }
        if (filters.creditorName) {
            requestFilters.creditor_name = filters.creditorName;
        }
        if (filters.debtorName) {
            requestFilters.debtor_name = filters.debtorName;
        }
        if (filters.searchText) {
            requestFilters.search = filters.searchText;
        }
        if (filters.aiCategorizedOnly) {
            requestFilters.category_source = 'ai';
        }

        const response = await axios.patch('/transactions/bulk', {
            filters: requestFilters,
            label_ids: label_ids,
            ...transactionData,
        });

        return await this.persistBulkResult(response.data);
    }

    private async persistBulkResult(
        data: Record<string, unknown>,
    ): Promise<BulkUpdateResult> {
        const sourceTransactions = Array.isArray(data.transactions)
            ? (data.transactions as Record<string, unknown>[])
            : [];
        const storedTransactions = sourceTransactions.map((transaction) =>
            transformTransactionFromServer(transaction),
        );
        const transactions = storedTransactions.map((transaction, index) => ({
            ...transaction,
            labels: Array.isArray(sourceTransactions[index].labels)
                ? (sourceTransactions[index]
                      .labels as ServerTransaction['labels'])
                : [],
        })) as unknown as ServerTransaction[];

        await withDb<void>(async () => {
            for (const transaction of storedTransactions) {
                await db.transactions.put(
                    transaction as unknown as Transaction,
                );
            }
        }, undefined);

        return {
            ...(data as unknown as BulkUpdateResult),
            updated_ids: Array.isArray(data.updated_ids)
                ? data.updated_ids.map(String)
                : [],
            skipped_split_ids: Array.isArray(data.skipped_split_ids)
                ? data.skipped_split_ids.map(String)
                : [],
            transactions,
        };
    }

    async delete(
        id: string,
        options?: { updateBalance?: boolean },
    ): Promise<void> {
        await axios.delete(`/transactions/${id}`, {
            data: options?.updateBalance ? { update_balance: true } : undefined,
        });
        // The API delete above is authoritative; the local cache eviction is
        // best-effort and skipped when IndexedDB is unavailable (PHP-LARAVEL-43).
        await withDb<void>(async () => {
            await db.transactions.delete(id);
        }, undefined);
    }

    async updateManyIndividual(
        updates: Array<{ id: string; data: TransactionUpdateData }>,
    ): Promise<void> {
        for (const { id, data } of updates) {
            await this.update(id, data);
        }
    }

    async deleteMany(
        ids: string[],
        options?: { updateBalance?: boolean },
    ): Promise<void> {
        for (const id of ids) {
            await this.delete(id, options);
        }
    }

    async checkDuplicates(
        accountId: string,
        transactions: Array<{
            transaction_date: string;
            amount: number;
            description: string;
        }>,
    ): Promise<boolean[]> {
        if (transactions.length === 0) {
            return [];
        }

        try {
            const response = await axios.post<{ duplicates: boolean[] }>(
                '/api/transactions/check-duplicates',
                {
                    account_id: accountId,
                    transactions: transactions.map((t) => ({
                        transaction_date: t.transaction_date,
                        amount: t.amount,
                        description: t.description,
                    })),
                },
            );

            return response.data.duplicates;
        } catch (error) {
            console.warn(
                'Duplicate check failed, assuming no duplicates:',
                error,
            );
            return transactions.map(() => false);
        }
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }

    async clearAll(): Promise<void> {
        await this.syncManager.clearAll();
    }
}

export const transactionSyncService = new TransactionSyncService();
