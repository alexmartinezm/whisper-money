import type { AutomationRule } from '@/types/automation-rule';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import AutomationRulesPage from './automation-rules';

const page = vi.hoisted(() => ({ automationRules: [] as unknown[] }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({
        props: {
            automationRules: page.automationRules,
            categories: [],
            labels: [],
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/automation-rules/create-automation-rule-dialog', () => ({
    CreateAutomationRuleDialog: () => null,
}));

vi.mock('@/components/automation-rules/edit-automation-rule-dialog', () => ({
    EditAutomationRuleDialog: () => null,
}));

vi.mock('@/components/automation-rules/delete-automation-rule-dialog', () => ({
    DeleteAutomationRuleDialog: () => null,
}));

vi.mock('@/components/automation-rules/apply-automation-rule-dialog', () => ({
    ApplyAutomationRuleDialog: () => null,
}));

vi.mock('@/components/automation-rules/post-save-apply-rule-prompt', () => ({
    PostSaveApplyRulePrompt: () => null,
}));

function makeRule(title: string, priority: number): AutomationRule {
    return {
        id: title,
        user_id: 'user-1',
        title,
        priority,
        origin: 'user',
        rules_json: {},
        action_category_id: null,
        action_note: null,
        action_note_iv: null,
        labels: [],
        created_at: '2026-06-15T00:00:00Z',
        updated_at: '2026-06-15T00:00:00Z',
        deleted_at: null,
    };
}

/** The rule titles as the table renders them, top to bottom. */
function renderedTitles(): string[] {
    return screen
        .getAllByRole('row')
        .slice(1)
        .map((row) => row.cells[1].textContent ?? '');
}

describe('Automation rules settings page', () => {
    it('lists the rules by priority, the order they are evaluated in', () => {
        page.automationRules = [
            makeRule('Last', 30),
            makeRule('First', 10),
            makeRule('Middle', 20),
        ];

        render(<AutomationRulesPage />);

        expect(renderedTitles()).toEqual(['First', 'Middle', 'Last']);
    });

    it('breaks ties on the title so equal priorities keep a stable order', () => {
        page.automationRules = [
            makeRule('Zulu', 0),
            makeRule('Alpha', 0),
            makeRule('Mike', 0),
        ];

        render(<AutomationRulesPage />);

        expect(renderedTitles()).toEqual(['Alpha', 'Mike', 'Zulu']);
    });

    it.each(['', '   ', 'not json', '"text"'])(
        'keeps the page up and marks a rule whose stored conditions are %j',
        (stored) => {
            page.automationRules = [
                {
                    ...makeRule('Broken', 10),
                    rules_json: stored as unknown as Record<string, unknown>,
                },
                makeRule('Fine', 20),
            ];

            render(<AutomationRulesPage />);

            expect(screen.getByText('Fine')).toBeInTheDocument();
            expect(screen.getAllByText('Invalid conditions')).toHaveLength(1);
        },
    );

    it('still reads conditions stored as a JSON string', () => {
        page.automationRules = [
            {
                ...makeRule('Encoded', 10),
                rules_json:
                    '{"in":["x",{"var":"description"}]}' as unknown as Record<
                        string,
                        unknown
                    >,
            },
        ];

        render(<AutomationRulesPage />);

        expect(screen.getByText('Encoded')).toBeInTheDocument();
        expect(
            screen.queryByText('Invalid conditions'),
        ).not.toBeInTheDocument();
    });

    it('reverses the order when the priority header is clicked', () => {
        page.automationRules = [
            makeRule('Last', 30),
            makeRule('First', 10),
            makeRule('Middle', 20),
        ];

        render(<AutomationRulesPage />);
        fireEvent.click(screen.getByRole('button', { name: 'Priority' }));

        expect(renderedTitles()).toEqual(['Last', 'Middle', 'First']);
    });
});
