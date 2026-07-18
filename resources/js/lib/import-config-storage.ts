import { type ColumnMapping, DateFormat } from '@/types/import';
import { type UUID } from '@/types/uuid';
import axios from 'axios';

interface ImportConfig {
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
}

function configUrl(accountId: UUID): string {
    return `/api/accounts/${accountId}/import-config`;
}

export async function saveImportConfig(
    accountId: UUID,
    config: ImportConfig,
): Promise<void> {
    try {
        await axios.put(configUrl(accountId), {
            type: 'transaction',
            config,
        });
    } catch (error) {
        console.error('Failed to save import configuration:', error);
    }
}

export async function loadImportConfig(
    accountId: UUID,
): Promise<ImportConfig | null> {
    try {
        const { data } = await axios.get<{ data: ImportConfig | null }>(
            configUrl(accountId),
            { params: { type: 'transaction' } },
        );

        const config = data.data;

        if (!config || !config.columnMapping || !config.dateFormat) {
            return null;
        }

        return config;
    } catch (error) {
        console.error('Failed to load import configuration:', error);
        return null;
    }
}
