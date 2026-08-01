import { Card, CardContent } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { index as budgetsIndex } from '@/routes/budgets';
import { type BudgetPace } from '@/types/recurring';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';

interface Props {
    budgets: BudgetPace[];
    currencyCode: string;
}

/**
 * Where a budget earns its place next to a forecast.
 *
 * The projected balance above deliberately does not fold allocations in: an
 * allocation is an intention, not a prediction, and most spending is not
 * budgeted at all. Held against the current pace instead, the same figure says
 * the one thing a balance never can — which category to slow down, and by how
 * much.
 */
export function BudgetPaceNotice({ budgets, currencyCode }: Props) {
    const locale = useLocale();

    if (budgets.length === 0) {
        return null;
    }

    const money = (cents: number) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currencyCode,
            maximumFractionDigits: 0,
        }).format(cents / 100);

    return (
        <Card>
            <CardContent className="flex flex-col gap-3 p-4">
                <div className="flex items-center gap-2">
                    <TrendingUp className="size-4 shrink-0 text-muted-foreground" />
                    <h3 className="text-sm font-medium">
                        {__('Heading over budget')}
                    </h3>
                </div>

                <ul className="flex flex-col gap-2">
                    {budgets.map((budget) => (
                        <li key={budget.id} className="text-sm">
                            {__(
                                'At this pace :name closes at :projected, :over over its :available.',
                                {
                                    name: budget.name,
                                    projected: money(budget.projected),
                                    over: money(budget.over_by),
                                    available: money(budget.available),
                                },
                            )}
                        </li>
                    ))}
                </ul>

                <Link
                    href={budgetsIndex.url()}
                    className="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                >
                    {__('Review budgets')}
                </Link>
            </CardContent>
        </Card>
    );
}
