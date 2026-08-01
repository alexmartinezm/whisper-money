import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { daysUntil } from '@/lib/recurring';
import { type UpcomingRecurringCharge } from '@/types/recurring';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';

interface Props {
    upcoming: UpcomingRecurringCharge[];
}

function whenLabel(isoDate: string, locale: string): string {
    const days = daysUntil(isoDate);

    if (days === 0) return __('Today');
    if (days === 1) return __('Tomorrow');
    if (days <= 7) return __('In :days days', { days: String(days) });

    return formatDateMedium(isoDate, locale);
}

export function UpcomingRecurringList({ upcoming }: Props) {
    const locale = useLocale();

    if (upcoming.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3" aria-labelledby="upcoming-recurring">
            <div>
                <h2 id="upcoming-recurring" className="text-base font-semibold">
                    {__('Coming up')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {__('Charges expected in the next 30 days')}
                </p>
            </div>

            <Card>
                <CardContent className="divide-y p-0">
                    {upcoming.map((charge) => (
                        <div
                            key={charge.id}
                            className="flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <div className="flex min-w-0 flex-col">
                                <span className="truncate font-medium">
                                    {charge.display_name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {whenLabel(charge.next_expected_on, locale)}
                                    {charge.category
                                        ? ` · ${charge.category.name}`
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
                                    amountInCents={charge.expected_amount}
                                    currencyCode={charge.currency_code}
                                    size="sm"
                                    weight="medium"
                                />
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>
        </section>
    );
}
