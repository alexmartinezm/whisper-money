import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StepFilled } from './step-screen';

describe('StepFilled', () => {
    it('sets each value where the sentence puts its placeholder', () => {
        const { container } = render(
            <p>
                <StepFilled
                    sentence="Something off? You can :link from Settings."
                    values={{ link: <a href="/undo">undo the import</a> }}
                />
            </p>,
        );

        expect(container.textContent).toBe(
            'Something off? You can undo the import from Settings.',
        );
        expect(container.querySelector('a')?.textContent).toBe(
            'undo the import',
        );
    });

    it('leaves a placeholder it has no value for as written', () => {
        const { container } = render(
            <p>
                <StepFilled sentence="Keep :this and :that" values={{}} />
            </p>,
        );

        expect(container.textContent).toBe('Keep :this and :that');
    });
});
