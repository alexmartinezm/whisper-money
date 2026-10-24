import { setTranslations } from '@/utils/i18n';
import { render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { SourceSplitNotice } from './step-review';

const spanish = JSON.parse(readFileSync('lang/es.json', 'utf8')) as Record<
    string,
    string
>;

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

    describe('in Spanish', () => {
        afterEach(() => setTranslations({}));

        it('speaks of transactions, as the rest of the full import does', () => {
            setTranslations(spanish);
            render(<SourceSplitNotice count={1} locale="es" />);

            expect(
                screen.getByText(
                    '1 transacción venía dividida en Money Pro: se importa sin categoría, con la nota y la etiqueta «Dividida en Money Pro» para que la dividas aquí.',
                ),
            ).toBeInTheDocument();
        });

        it('in the plural too', () => {
            setTranslations(spanish);
            render(<SourceSplitNotice count={1204} locale="es" />);

            expect(
                screen.getByText(
                    '1204 transacciones venían divididas en Money Pro: se importan sin categoría, con la nota y la etiqueta «Dividida en Money Pro» para que las dividas aquí.',
                ),
            ).toBeInTheDocument();
        });
    });
});
