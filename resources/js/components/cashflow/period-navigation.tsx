import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { CashflowPeriodType } from '@/hooks/use-cashflow-data';
import { useLocale } from '@/hooks/use-locale';
import {
    getUserPeriodRange,
    sameUserPeriod,
    shiftUserPeriod,
    UserMonthStartDay,
} from '@/lib/user-periods';
import { cn } from '@/lib/utils';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { getQuarter } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PeriodNavigationProps {
    currentDate: Date;
    periodType: CashflowPeriodType;
    monthStartDay: UserMonthStartDay;
    onDateChange: (date: Date) => void;
    onPeriodTypeChange: (periodType: CashflowPeriodType) => void;
}

const periodTypeOptions: Array<{
    value: CashflowPeriodType;
    labelKey: string;
}> = [
    { value: 'month', labelKey: 'Month' },
    { value: 'quarter', labelKey: 'Quarter' },
    { value: 'year', labelKey: 'Year' },
];

export function PeriodNavigation({
    currentDate,
    periodType,
    monthStartDay,
    onDateChange,
    onPeriodTypeChange,
}: PeriodNavigationProps) {
    const locale = useLocale();
    const now = new Date();
    const periodStart = getUserPeriodRange(
        currentDate,
        periodType,
        monthStartDay,
    ).from;
    const isCurrentPeriod = sameUserPeriod(
        currentDate,
        now,
        periodType,
        monthStartDay,
    );

    const handlePreviousPeriod = () => {
        onDateChange(
            shiftUserPeriod(currentDate, periodType, monthStartDay, -1),
        );
    };

    const handleNextPeriod = () => {
        onDateChange(
            shiftUserPeriod(currentDate, periodType, monthStartDay, 1),
        );
    };

    const handleCurrentPeriod = () => {
        onDateChange(now);
    };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <ButtonGroup className="w-full sm:w-fit">
                {periodTypeOptions.map((option) => (
                    <Button
                        key={option.value}
                        type="button"
                        variant={
                            periodType === option.value ? 'default' : 'outline'
                        }
                        onClick={() => onPeriodTypeChange(option.value)}
                        className={cn(
                            'flex-1 sm:flex-none',
                            periodType === option.value &&
                                'border-primary bg-primary text-primary-foreground',
                        )}
                    >
                        {__(option.labelKey)}
                    </Button>
                ))}
            </ButtonGroup>

            <ButtonGroup className="w-full sm:w-fit">
                <Button
                    variant="outline"
                    size="icon"
                    onClick={handlePreviousPeriod}
                    aria-label={__('Previous period')}
                >
                    <ChevronLeft className="size-4" />
                </Button>

                <Button
                    onClick={handleCurrentPeriod}
                    variant="outline"
                    className="flex-1 sm:flex-none"
                >
                    {formatPeriodLabel(periodStart, periodType, locale)}
                </Button>

                <Button
                    variant="outline"
                    size="icon"
                    onClick={handleNextPeriod}
                    disabled={isCurrentPeriod}
                    aria-label={__('Next period')}
                >
                    <ChevronRight className="size-4" />
                </Button>
            </ButtonGroup>
        </div>
    );
}

function formatPeriodLabel(
    date: Date,
    periodType: CashflowPeriodType,
    locale: string,
): string {
    if (periodType === 'quarter') {
        return `${__('Q')}${getQuarter(date)} ${formatDate(date, 'yyyy', locale)}`;
    }

    if (periodType === 'year') {
        return formatDate(date, 'yyyy', locale);
    }

    return formatMonthYear(date, locale);
}
