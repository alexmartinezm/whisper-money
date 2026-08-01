import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { daysUntil } from '@/lib/recurring';
import { cn } from '@/lib/utils';
import { type CashflowForecast } from '@/types/recurring';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { TriangleAlert } from 'lucide-react';

interface Props {
    forecast: CashflowForecast;
}

function whenLabel(isoDate: string, locale: string): string {
    const days = daysUntil(isoDate);

    if (days === 0) return __('Today');
    if (days === 1) return __('Tomorrow');
    if (days <= 7) return __('In :days days', { days: String(days) });

    return formatDateMedium(isoDate, locale);
}

export function CashflowForecastCard({ forecast }: Props) {
    const locale = useLocale();

    if (forecast.occurrences.length === 0) {
        return null;
    }

    // The warning is read off the estimate, not the exact walk. Recurring
    // charges alone almost never empty an account — the month's shopping does,
    // and an alert that cannot see it would stay silent through the dip it
    // exists to catch.
    const dip = forecast.spending?.lowest ?? forecast.lowest;
    const goesNegative = dip.balance < 0;
    const money = (cents: number, round = false) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: forecast.currency_code,
            ...(round ? { maximumFractionDigits: 0 } : {}),
        }).format(cents / 100);

    return (
        <section className="space-y-3" aria-labelledby="cashflow-forecast">
            <div>
                <h2 id="cashflow-forecast" className="text-base font-semibold">
                    {__('Next :days days', { days: String(forecast.days) })}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {__(
                        'The cash you can actually spend, walked forward through the charges expected below.',
                    )}
                </p>
            </div>

            <Card>
                <CardContent className="space-y-4 p-4">
                    <div className="grid gap-4 sm:grid-cols-4">
                        {[
                            {
                                key: 'start',
                                label: __('Today'),
                                amount: forecast.starting_balance,
                            },
                            {
                                key: 'in',
                                label: __('Expected in'),
                                amount: forecast.expected_in,
                            },
                            {
                                key: 'out',
                                label: __('Expected out'),
                                amount: forecast.expected_out,
                            },
                            {
                                key: 'end',
                                label: __('Projected'),
                                amount: forecast.ending_balance,
                            },
                        ].map((cell) => (
                            <div key={cell.key} className="flex flex-col gap-1">
                                <span className="text-xs text-muted-foreground">
                                    {cell.label}
                                </span>
                                <AmountDisplay
                                    amountInCents={cell.amount}
                                    currencyCode={forecast.currency_code}
                                    size="lg"
                                    weight="semibold"
                                />
                            </div>
                        ))}
                    </div>

                    {forecast.spending && (
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'You also spend about :monthly a month outside these charges. Counting it, you would close around :projected.',
                                {
                                    monthly: money(
                                        Math.abs(forecast.spending.monthly),
                                        true,
                                    ),
                                    projected: money(
                                        forecast.spending.ending_balance,
                                        true,
                                    ),
                                },
                            )}
                        </p>
                    )}

                    {goesNegative && (
                        <div
                            // In dark mode `--destructive` is the darker of the
                            // pair, so red text on a red tint loses contrast;
                            // the foreground token is the lighter one there.
                            className="flex items-start gap-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive dark:bg-destructive/20 dark:text-destructive-foreground"
                            role="alert"
                        >
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {forecast.spending
                                    ? __(
                                          'At your usual pace you would be down to :amount around :date.',
                                          {
                                              amount: money(dip.balance),
                                              date: formatDateMedium(
                                                  dip.date,
                                                  locale,
                                              ),
                                          },
                                      )
                                    : __(
                                          'Runs out around :date, down to :amount.',
                                          {
                                              date: formatDateMedium(
                                                  dip.date,
                                                  locale,
                                              ),
                                              amount: money(dip.balance),
                                          },
                                      )}
                            </span>
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        {forecast.accounts.length > 0
                            ? __('Counting :accounts.', {
                                  accounts: forecast.accounts
                                      .map((account) => account.name)
                                      .join(', '),
                              })
                            : __(
                                  'No current or savings account to count, so today reads as zero.',
                              )}
                    </p>

                    {forecast.other_currencies.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {__(
                                'Series in :currencies are not included; amounts are never converted.',
                                {
                                    currencies:
                                        forecast.other_currencies.join(', '),
                                },
                            )}
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardContent className="divide-y p-0">
                    {forecast.occurrences.map((charge, index) => (
                        <div
                            key={`${charge.series_id}-${charge.date}-${index}`}
                            className="flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <div className="flex min-w-0 flex-col">
                                <span className="truncate font-medium">
                                    {charge.display_name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {whenLabel(charge.date, locale)}
                                    {charge.category
                                        ? ` · ${charge.category}`
                                        : ''}
                                </span>
                            </div>
                            <div className="flex shrink-0 flex-col items-end">
                                <div className="flex items-center gap-2">
                                    {charge.amount_is_variable && (
                                        <span className="text-xs text-muted-foreground">
                                            {__('approx.')}
                                        </span>
                                    )}
                                    <AmountDisplay
                                        amountInCents={charge.amount}
                                        currencyCode={forecast.currency_code}
                                        size="sm"
                                        weight="medium"
                                    />
                                </div>
                                <span
                                    className={cn(
                                        'text-xs text-muted-foreground',
                                        charge.balance_after < 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    {money(charge.balance_after, true)}
                                </span>
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>
        </section>
    );
}
