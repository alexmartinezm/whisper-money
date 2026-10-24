import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepCreateAccount } from './step-create-account';

// The price and the plan condition are one line inside the connected row now,
// so a single matcher covers both.
const PLAN_NOTICE = /^Standard plan, from\s*€.*choose a plan at the end/;
const PLAN_CONDITION = /choose a plan at the end of the onboarding/;

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return { usePage: () => ({ props: pageProps }) };
});

// The manual form pulls in the account form's own data fetching, which has
// nothing to do with the plan intent.
vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

// The connected flow parked on its bank list with nothing in it, which is the
// screen whose wording depends on open banking. Fetching the catalogue and
// merging the API-key providers into it is the dialog suite's business.
vi.mock('@/hooks/use-connect-flow', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/hooks/use-connect-flow')>()),
    useConnectFlow: () => ({
        step: 'bank',
        setStep: vi.fn(),
        country: 'ES',
        setCountry: vi.fn(),
        filteredInstitutions: [],
        searchQuery: '',
        setSearchQuery: vi.fn(),
        selectedBank: null,
        setSelectedBank: vi.fn(),
        isLoading: false,
        isSubmitting: false,
        error: null,
        credentials: {},
        setCredential: vi.fn(),
        provider: null,
        connectedBankNames: new Set<string>(),
        isAlreadyConnected: false,
        acknowledgedReplace: false,
        setAcknowledgedReplace: vi.fn(),
        canSubmit: false,
        fetchInstitutions: vi.fn(),
        handleAuthorize: vi.fn(),
        reset: vi.fn(),
        clearBankSelection: vi.fn(),
    }),
}));

function renderStep(signupPlan: 'free' | 'paid' | null) {
    render(
        <StepCreateAccount
            banks={[]}
            isFirstAccount
            signupPlan={signupPlan}
            onAccountCreated={vi.fn()}
        />,
    );
}

describe('StepCreateAccount plan intent', () => {
    it('offers no connected option to a free signup', () => {
        renderStep('free');

        expect(screen.queryByText('Connected')).not.toBeInTheDocument();
        expect(screen.getByTestId('account-form')).toBeInTheDocument();
    });

    it('quotes no price to a paid signup, who has just seen one', () => {
        renderStep('paid');

        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(screen.queryByText(PLAN_CONDITION)).not.toBeInTheDocument();
    });

    it('keeps the connected option, the price and the plan warning for every other signup', () => {
        renderStep(null);

        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(screen.getByText(PLAN_NOTICE)).toBeInTheDocument();
    });
});

/**
 * A self-hosted install may carry no EnableBanking credentials. The brokers and
 * exchanges still connect with their own API key, so the connected way in stays
 * and stops calling itself a bank.
 */
describe('StepCreateAccount and open banking', () => {
    afterEach(async () => {
        const { pageProps } = await import('@/lib/onboarding-page-props');

        pageProps.openBankingEnabled = true;
    });

    it('promises a bank when open banking is configured', () => {
        renderStep('paid');

        expect(
            screen.getByText('Auto-sync transactions directly from your bank'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText('Connected'));

        expect(screen.getByText('Connect Your Bank')).toBeInTheDocument();
        expect(
            screen.getByText('Select your country and bank to get started.'),
        ).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Search banks...'),
        ).toBeInTheDocument();
        expect(screen.getByText('No banks found.')).toBeInTheDocument();
    });

    it('offers brokers and exchanges in place of the bank when it is off', async () => {
        const { pageProps } = await import('@/lib/onboarding-page-props');
        pageProps.openBankingEnabled = false;

        renderStep('paid');

        expect(
            screen.queryByText(
                'Auto-sync transactions directly from your bank',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Connect a broker or an exchange and sync it automatically.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Manual')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Connected'));

        expect(screen.queryByText('Connect Your Bank')).not.toBeInTheDocument();
        expect(screen.getByText('Connect an account')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Select your country and provider to get started.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Search providers...'),
        ).toBeInTheDocument();
        expect(screen.getByText('No providers found.')).toBeInTheDocument();
    });
});
