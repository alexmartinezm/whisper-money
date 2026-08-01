import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { type RecurringCurrencySummary } from '@/types/recurring';
import { __ } from '@/utils/i18n';

interface Props {
    summary: RecurringCurrencySummary[];
}

export function RecurringSummaryCards({ summary }: Props) {
    if (summary.length === 0) {
        return null;
    }

    // Amounts in different currencies are never added together, so each
    // currency gets its own set of totals. The common single-currency case
    // renders exactly as before, without a heading.
    const showCurrencyHeadings = summary.length > 1;

    return (
        <div className="space-y-4">
            {summary.map((totals) => (
                <section key={totals.currency_code} className="space-y-2">
                    {showCurrencyHeadings && (
                        <h3 className="text-sm font-medium text-muted-foreground">
                            {totals.currency_code}
                        </h3>
                    )}
                    <div className="grid gap-4 sm:grid-cols-3">
                        {[
                            {
                                key: 'monthly',
                                label: __('Recurring per month'),
                                amount: totals.monthly_expense,
                            },
                            {
                                key: 'yearly',
                                label: __('Recurring per year'),
                                amount: totals.yearly_expense,
                            },
                            {
                                key: 'income',
                                label: __('Recurring income per month'),
                                amount: totals.monthly_income,
                            },
                        ].map((card) => (
                            <Card key={card.key}>
                                <CardContent className="flex flex-col gap-1 p-4">
                                    <span className="text-sm text-muted-foreground">
                                        {card.label}
                                    </span>
                                    <AmountDisplay
                                        amountInCents={card.amount}
                                        currencyCode={totals.currency_code}
                                        size="xl"
                                        weight="semibold"
                                    />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
