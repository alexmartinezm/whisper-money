import { type ConnectedAppRow } from '@/components/access-tokens/connected-app-list';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import McpPage from './mcp';

const pageProps = vi.hoisted(() => ({
    auth: { hasProPlan: true },
    tokens: [],
    connectedApps: [] as ConnectedAppRow[],
    serverUrl: 'https://whisper.money/mcp',
    oauthUrl: 'https://whisper.money/mcp/oauth',
    subscribeUrl: '/subscribe',
    newToken: null as string | null,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    router: { post: vi.fn(), delete: vi.fn() },
    useForm: () => ({
        data: { name: '', scope: 'read' },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('MCP settings page', () => {
    beforeEach(() => {
        pageProps.newToken = null;
        pageProps.connectedApps = [];
    });

    it('lists the assistants connected through OAuth', () => {
        pageProps.connectedApps = [
            {
                id: 'client-1',
                name: 'Claude',
                verified: true,
                redirect: 'https://claude.ai',
                scopes: ['AI Connector'],
                connected_at: '2026-10-01T00:00:00+00:00',
                last_used_at: null,
            },
        ];

        render(<McpPage />);

        expect(screen.getByText('Connected apps')).toBeTruthy();
        expect(screen.getByText('Verified')).toBeTruthy();
        expect(screen.getByText('https://claude.ai')).toBeTruthy();
        expect(screen.queryByText(/Last used/)).toBeNull();
    });

    it('shows a freshly created token once, with the Claude Code section open', () => {
        pageProps.newToken = 'wm_secret_token';

        render(<McpPage />);

        expect(screen.getByText('wm_secret_token')).toBeTruthy();
        expect(screen.getByText('Create a token')).toBeTruthy();
    });
});
