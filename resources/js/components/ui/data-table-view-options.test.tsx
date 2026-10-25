import { type Table } from '@tanstack/react-table';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DataTableViewOptions } from './data-table-view-options';

function tableWithHideableColumn(): Table<unknown> {
    return {
        getAllLeafColumns: () => [
            {
                id: 'date',
                columnDef: { header: 'Date' },
                getCanHide: () => true,
                getIsVisible: () => true,
                toggleVisibility: vi.fn(),
            },
        ],
    } as unknown as Table<unknown>;
}

describe('DataTableViewOptions', () => {
    /**
     * Below `sm` the bar above the transactions list drops to icons, so the
     * trigger squares around its icon there and keeps its name for screen
     * readers.
     */
    it('squares the trigger around its icon below sm and keeps its name', () => {
        render(<DataTableViewOptions table={tableWithHideableColumn()} />);

        const trigger = screen.getByRole('button', { name: 'Columns' });

        expect(trigger).toHaveClass('max-sm:size-9', 'max-sm:px-0');
        expect(within(trigger).getByText('Columns')).toHaveClass(
            'hidden',
            'sm:inline',
        );
    });

    it('keeps the label and the padding when told to show the text on mobile', () => {
        render(
            <DataTableViewOptions
                table={tableWithHideableColumn()}
                hideColumnTextOnMobile={false}
            />,
        );

        const trigger = screen.getByRole('button', { name: 'Columns' });

        expect(trigger).not.toHaveClass('max-sm:size-9');
        expect(within(trigger).getByText('Columns')).not.toHaveClass('hidden');
    });
});
