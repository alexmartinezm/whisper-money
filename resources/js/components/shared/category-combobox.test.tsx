import { Shortcut } from '@/components/shortcuts/shortcut';
import {
    searchableCategories as categories,
    expectFoodListedWithSubcategories,
    listedOptions,
} from '@/lib/category-tree.fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CategoryCombobox } from './category-combobox';

function search(query: string) {
    const onValueChange = vi.fn();
    render(
        <CategoryCombobox
            value={null}
            onValueChange={onValueChange}
            categories={categories}
        />,
    );

    fireEvent.click(screen.getByRole('combobox'));
    const input = screen.getByPlaceholderText('Search categories...');
    fireEvent.change(input, { target: { value: query } });

    return { input, onValueChange };
}

describe('CategoryCombobox search', () => {
    it('lists the subcategories of a matching parent and picks the parent on Enter', async () => {
        const { input, onValueChange } = search('food');

        await expectFoodListedWithSubcategories();
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onValueChange).toHaveBeenCalledWith('food');
    });

    it('shows a matching subcategory under its parent without its siblings', () => {
        search('groc');

        expect(listedOptions()).toEqual(['Food', 'Groceries']);
    });
});

describe('CategoryCombobox and keyboard shortcuts', () => {
    it('keeps Ctrl+Enter for the pick, so the dialog does not save under it', () => {
        const save = vi.fn();
        const onValueChange = vi.fn();
        render(
            <>
                <Shortcut id="transaction-dialog.save" onTrigger={save} />
                <CategoryCombobox
                    value={null}
                    onValueChange={onValueChange}
                    categories={categories}
                />
            </>,
        );

        fireEvent.click(screen.getByRole('combobox'));
        const input = screen.getByPlaceholderText('Search categories...');
        fireEvent.change(input, { target: { value: 'food' } });
        fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true });

        expect(save).not.toHaveBeenCalled();
    });

    it('lets Ctrl+Enter save once the picker is closed', () => {
        const save = vi.fn();
        render(
            <>
                <Shortcut id="transaction-dialog.save" onTrigger={save} />
                <CategoryCombobox
                    value={null}
                    onValueChange={vi.fn()}
                    categories={categories}
                />
            </>,
        );

        fireEvent.keyDown(screen.getByRole('combobox'), {
            key: 'Enter',
            ctrlKey: true,
        });

        expect(save).toHaveBeenCalledOnce();
    });
});
