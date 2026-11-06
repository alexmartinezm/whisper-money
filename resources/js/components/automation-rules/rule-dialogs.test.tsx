import type { DecryptedTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AutomateCategorizationDialog } from './automate-categorization-dialog';
import { AutomationRulesDialog } from './automation-rules-dialog';

const brokenRule = {
    id: 'broken',
    user_id: 'user-1',
    title: 'Broken',
    priority: 1,
    origin: 'user',
    rules_json: '',
    action_category_id: null,
    action_note: null,
    action_note_iv: null,
    labels: [],
    created_at: '2026-06-15T00:00:00Z',
    updated_at: '2026-06-15T00:00:00Z',
    deleted_at: null,
};

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: { automationRules: [brokenRule], categories: [], labels: [] },
    }),
}));

vi.mock('@/components/automation-rules/automation-rule-form', () => ({
    AutomationRuleForm: () => null,
}));

vi.mock('@/components/automation-rules/create-automation-rule-dialog', () => ({
    CreateAutomationRuleDialog: () => null,
}));

vi.mock('@/components/automation-rules/edit-automation-rule-dialog', () => ({
    EditAutomationRuleDialog: () => null,
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

vi.mock('@/components/automation-rules/delete-automation-rule-dialog', () => ({
    DeleteAutomationRuleDialog: () => null,
}));

describe('one bad stored rule', () => {
    it('leaves the automation rules dialog up and marks the rule', () => {
        render(<AutomationRulesDialog open onOpenChange={() => {}} />);

        expect(screen.getByText('Broken')).toBeInTheDocument();
        expect(screen.getByText('Invalid conditions')).toBeInTheDocument();
    });

    it('leaves the automate categorization dialog up and marks the rule', () => {
        render(
            <AutomateCategorizationDialog
                open
                candidate={{
                    transaction: {
                        description: 'Market',
                        decryptedDescription: 'Market',
                    } as DecryptedTransaction,
                    category: null,
                }}
                categories={[]}
                onOpenChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByText('Modify an existing rule'));

        expect(screen.getByText('Broken')).toBeInTheDocument();
        expect(screen.getByText('Invalid conditions')).toBeInTheDocument();
    });
});
