import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { type OutlookMonth } from '@/types/recurring';
import { __ } from '@/utils/i18n';
import { Fragment } from 'react';

interface Props {
    months: OutlookMonth[];
    currencyCode: string;
}

/** "2026-10" as the month name in the user's language. */
function monthLabel(month: string, locale: string): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const formatted = new Intl.DateTimeFormat(locale, {
        month: 'long',
        year: 'numeric',
    }).format(new Date(Date.UTC(year, monthNumber - 1, 1)));

    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

function dayLabel(isoDate: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
    }).format(new Date(isoDate));
}

export function RecurringOutlook({ months, currencyCode }: Props) {
    const locale = useLocale();

    if (months.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3" aria-labelledby="recurring-outlook">
            <div>
                <h2 id="recurring-outlook" className="text-base font-semibold">
                    {__('Further out')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {__(
                        'Charges that do not come round every month, so they never land in the projection above. No running balance here — it would ignore everything you spend day to day.',
                    )}
                </p>
            </div>

            <Card>
                <CardContent className="p-0">
                    {months.map((month) => (
                        <Fragment key={month.month}>
                            <div className="flex items-center justify-between gap-3 border-b bg-muted/50 px-4 py-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    {monthLabel(month.month, locale)}
                                </span>
                                {month.charges.length > 1 && (
                                    <AmountDisplay
                                        amountInCents={month.total}
                                        currencyCode={currencyCode}
                                        size="sm"
                                        weight="medium"
                                    />
                                )}
                            </div>
                            {month.charges.map((charge) => (
                                <div
                                    key={`${charge.series_id}-${charge.date}`}
                                    className="flex items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0"
                                >
                                    <div className="flex min-w-0 flex-col">
                                        <span className="truncate font-medium">
                                            {charge.display_name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {dayLabel(charge.date, locale)}
                                            {charge.category
                                                ? ` · ${charge.category}`
                                                : ''}
                                        </span>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {charge.amount_is_variable && (
                                            <span className="text-xs text-muted-foreground">
                                                {__('approx.')}
                                            </span>
                                        )}
                                        <AmountDisplay
                                            amountInCents={charge.amount}
                                            currencyCode={currencyCode}
                                            size="sm"
                                            weight="medium"
                                        />
                                    </div>
                                </div>
                            ))}
                        </Fragment>
                    ))}
                </CardContent>
            </Card>
        </section>
    );
}
