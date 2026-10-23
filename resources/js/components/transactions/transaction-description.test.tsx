import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { setTranslations } from '@/utils/i18n';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    ENCRYPTED_PLACEHOLDER,
    TransactionDescription,
} from './transaction-description';

function renderWithPrivacy(ui: React.ReactElement) {
    return render(<PrivacyModeProvider>{ui}</PrivacyModeProvider>);
}

describe('TransactionDescription', () => {
    afterEach(() => {
        setTranslations({});
    });

    it('renders the plaintext description when not encrypted', () => {
        renderWithPrivacy(
            <TransactionDescription text="Coffee at Starbucks" />,
        );

        expect(screen.getByText('Coffee at Starbucks')).toBeInTheDocument();
    });

    it('masks a still-encrypted value instead of leaking raw ciphertext', () => {
        const ciphertext = 'U2FsdGVkX1+abc123def456==';

        renderWithPrivacy(
            <TransactionDescription text={ciphertext} encrypted />,
        );

        expect(screen.queryByText(ciphertext)).not.toBeInTheDocument();
        expect(screen.getByText(ENCRYPTED_PLACEHOLDER)).toBeInTheDocument();
    });

    it.each<{
        locale: string;
        translations: Record<string, string>;
        fake: string;
    }>([
        { locale: 'English', translations: {}, fake: 'Gym Membership' },
        {
            locale: 'Spanish',
            translations: { 'Gym Membership': 'Cuota del gimnasio' },
            fake: 'Cuota del gimnasio',
        },
    ])(
        'hides the description behind the same fake concept in $locale when privacy mode is on',
        ({ translations, fake }) => {
            localStorage.setItem('privacy-mode-enabled', 'true');
            setTranslations(translations);

            renderWithPrivacy(
                <TransactionDescription text="Coffee at Starbucks" />,
            );

            expect(screen.getByText(fake)).toBeInTheDocument();
            expect(
                screen.queryByText('Coffee at Starbucks'),
            ).not.toBeInTheDocument();
        },
    );
});
