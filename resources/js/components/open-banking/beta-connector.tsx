import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { __ } from '@/utils/i18n';
import { FlaskConical } from 'lucide-react';

/** Matches the badge, so the pill and the notice read as the same signal. */
const AMBER =
    'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300';

/**
 * Flags the banks on our curated beta list (`banking.beta_banks`): the ones
 * whose connections we have seen fail more often than the rest.
 *
 * Surfaced as a plain pill rather than a warning: most of these connections do
 * work, and it has to sit inside the bank-picker rows, which are buttons.
 */
export function BetaConnectorBadge() {
    return (
        <Badge variant="secondary" className={AMBER}>
            {__('Beta')}
        </Badge>
    );
}

/**
 * The same signal spelled out, for the confirm step — the one moment the user
 * can still pick a different bank. Says nothing about when a connector leaves
 * beta: that depends on how the bank behaves, not on a date we can promise.
 */
export function BetaConnectorNotice() {
    return (
        <Alert className={AMBER}>
            <FlaskConical />
            <AlertTitle>{__('This bank is still in beta')}</AlertTitle>
            <AlertDescription className="text-amber-700 dark:text-amber-300">
                {__(
                    'Connections to this bank fail or pause more often than with other banks. It usually works, and you can reconnect or disconnect whenever you want.',
                )}
            </AlertDescription>
        </Alert>
    );
}
