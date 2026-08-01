import { Badge } from '@/components/ui/badge';
import { type RecurringCadence } from '@/types/recurring';
import { __ } from '@/utils/i18n';

const LABELS: Record<RecurringCadence, () => string> = {
    weekly: () => __('Weekly'),
    biweekly: () => __('Bi-weekly'),
    monthly: () => __('Monthly'),
    quarterly: () => __('Quarterly'),
    yearly: () => __('Yearly'),
};

export function cadenceLabel(cadence: RecurringCadence): string {
    return LABELS[cadence]();
}

export function RecurringCadenceBadge({
    cadence,
}: {
    cadence: RecurringCadence;
}) {
    return (
        <Badge variant="secondary" className="font-normal">
            {cadenceLabel(cadence)}
        </Badge>
    );
}
