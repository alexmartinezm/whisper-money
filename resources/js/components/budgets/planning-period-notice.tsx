import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { __ } from '@/utils/i18n';
import { CalendarClock } from 'lucide-react';

export function PlanningPeriodNotice() {
    return (
        <Alert className="border-amber-500/50 bg-amber-50 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
            <CalendarClock className="h-4 w-4" />
            <AlertTitle>{__('Planning next period')}</AlertTitle>
            <AlertDescription>
                {__(
                    'This period is ready for planning. Spending and transactions will appear when it starts.',
                )}
            </AlertDescription>
        </Alert>
    );
}
