import type { BalanceColumnMapping } from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import type { UUID } from '@/types/uuid';
import axios from 'axios';

interface BalanceImportConfig {
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
}

function configUrl(accountId: UUID): string {
    return `/api/accounts/${accountId}/import-config`;
}

export async function saveBalanceImportConfig(
    accountId: UUID,
    config: BalanceImportConfig,
): Promise<void> {
    try {
        await axios.put(configUrl(accountId), {
            type: 'balance',
            config,
        });
    } catch (error) {
        console.error('Failed to save balance import configuration:', error);
    }
}

export async function loadBalanceImportConfig(
    accountId: UUID,
): Promise<BalanceImportConfig | null> {
    try {
        const { data } = await axios.get<{ data: BalanceImportConfig | null }>(
            configUrl(accountId),
            { params: { type: 'balance' } },
        );

        const config = data.data;

        if (!config || !config.columnMapping || !config.dateFormat) {
            return null;
        }

        return config;
    } catch (error) {
        console.error('Failed to load balance import configuration:', error);
        return null;
    }
}
