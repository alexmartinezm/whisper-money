import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SourceSplitNotice } from './step-review';

describe('SourceSplitNotice', () => {
    it('says nothing when no row came split', () => {
        const { container } = render(
            <SourceSplitNotice count={0} locale="en" />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('says how many rows came split and what happens to them', () => {
        render(<SourceSplitNotice count={1204} locale="en" />);

        expect(
            screen.getByText(
                '1,204 transactions came split in Money Pro: they are imported without a category, with the note and the label «Split in Money Pro» so you can split them here.',
            ),
        ).toBeInTheDocument();
    });

    it('speaks of a single row in the singular', () => {
        render(<SourceSplitNotice count={1} locale="en" />);

        expect(
            screen.getByText(
                '1 transaction came split in Money Pro: it is imported without a category, with the note and the label «Split in Money Pro» so you can split it here.',
            ),
        ).toBeInTheDocument();
    });
});
