import type { Account } from '@/types/account';
import type { BankingConnection } from '@/types/banking';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import ManageAccountsPage from './manage-accounts';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

function syncedAccount(externalAccountId: string | null): Account {
    return {
        id: 'account-1',
        name: 'Nómina',
        name_iv: null,
        encrypted: false,
        currency_code: 'EUR',
        external_account_id: externalAccountId,
    } as unknown as Account;
}

function renderPage(account: Account) {
    render(
        <ManageAccountsPage
            connection={{ id: 'connection-1' } as BankingConnection}
            syncedAccounts={[account]}
            availableAccounts={[]}
            discoveredAccounts={null}
        />,
    );
}

describe('ManageAccountsPage', () => {
    it('says a linked account is syncing', () => {
        renderPage(syncedAccount('uid-1'));

        expect(screen.getByText(/EUR/)).toHaveTextContent('Syncing');
    });

    it('says an account that lost its bank link is not syncing', () => {
        renderPage(syncedAccount(null));

        expect(screen.getByText(/EUR/)).toHaveTextContent('Not syncing');
    });
});
