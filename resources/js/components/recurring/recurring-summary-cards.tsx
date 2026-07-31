import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { type RecurringSummary } from '@/types/recurring';
import { __ } from '@/utils/i18n';

interface Props {
    summary: RecurringSummary;
    currencyCode: string;
}

export function RecurringSummaryCards({ summary, currencyCode }: Props) {
    const cards = [
        {
            key: 'monthly',
            label: __('Recurring per month'),
            amount: summary.monthly_expense,
        },
        {
            key: 'yearly',
            label: __('Recurring per year'),
            amount: summary.yearly_expense,
        },
        {
            key: 'income',
            label: __('Recurring income per month'),
            amount: summary.monthly_income,
        },
    ];

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            {cards.map((card) => (
                <Card key={card.key}>
                    <CardContent className="flex flex-col gap-1 p-4">
                        <span className="text-sm text-muted-foreground">
                            {card.label}
                        </span>
                        <AmountDisplay
                            amountInCents={card.amount}
                            currencyCode={currencyCode}
                            size="xl"
                            weight="semibold"
                        />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
