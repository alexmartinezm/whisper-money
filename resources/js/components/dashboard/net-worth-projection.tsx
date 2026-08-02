import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    type ChartConfig,
} from '@/components/ui/chart';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type NetWorthProjection } from '@/types/net-worth';
import { __ } from '@/utils/i18n';
import {
    Area,
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

interface Props {
    projection: NetWorthProjection;
}

/**
 * The three lines, in the order they should be read. Built inside the render
 * rather than at module scope: `__()` needs the translations the page ships
 * with, and a module constant is evaluated before they arrive.
 */
function buildLegend() {
    return [
        {
            key: 'actual' as const,
            label: __('So far'),
            color: 'var(--color-chart-1)',
            dash: undefined,
        },
        {
            key: 'committed' as const,
            label: __('Committed'),
            color: 'var(--color-chart-1)',
            dash: '6 4',
        },
        {
            key: 'estimated' as const,
            label: __('With your usual spending'),
            color: 'var(--color-chart-3)',
            dash: '2 4',
        },
    ];
}

/**
 * The band between the two forward lines. Recharts draws an `Area` from a
 * `[low, high]` pair, so the gap between "committed" and "estimated" becomes a
 * shape rather than two lines the eye has to bridge on its own.
 */
function withBand(projection: NetWorthProjection) {
    return projection.points.map((point) => ({
        ...point,
        range:
            point.committed !== null && point.estimated !== null
                ? ([
                      Math.min(point.committed, point.estimated),
                      Math.max(point.committed, point.estimated),
                  ] as [number, number])
                : null,
    }));
}

export function NetWorthProjectionCard({ projection }: Props) {
    const locale = useLocale();
    const legend = buildLegend();
    const chartConfig = Object.fromEntries(
        legend.map((line) => [
            line.key,
            { label: line.label, color: line.color },
        ]),
    ) satisfies ChartConfig;
    const data = withBand(projection);
    const today = projection.points.find(
        (point) => point.actual !== null && point.committed !== null,
    );

    const money = (cents: number, round = true) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: projection.currency_code,
            ...(round ? { maximumFractionDigits: 0 } : {}),
        }).format(cents / 100);

    const compact = (cents: number) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: projection.currency_code,
            notation: 'compact',
            maximumFractionDigits: 0,
        }).format(cents / 100);

    // The axis spans two calendar years, so every tick carries one. Marking
    // only January does not survive tick thinning: the axis is free to drop it.
    const monthLabel = (iso: string) => {
        const date = new Date(`${iso}T00:00:00`);

        return new Intl.DateTimeFormat(locale, {
            month: 'short',
            year: '2-digit',
        }).format(date);
    };

    // Anchoring the axis at zero would flatten a year of movement into a flat
    // line: the whole point of the card is the slope, not the absolute height.
    const values = data.flatMap((point) =>
        [point.actual, point.committed, point.estimated].filter(
            (value): value is number => value !== null,
        ),
    );
    const low = Math.min(...values);
    const high = Math.max(...values);
    const pad = Math.max((high - low) * 0.15, 1);

    const horizon = projection.estimated ?? projection.committed;
    const change = horizon - projection.today;

    const drivers = [
        {
            key: 'debt',
            label: __('Debt repaid'),
            amount: projection.drivers.debt_repaid,
        },
        {
            key: 'property',
            label: __('Revaluation'),
            amount: projection.drivers.property_growth,
        },
        {
            key: 'recurring-in',
            label: __('Recurring income'),
            amount: projection.drivers.recurring_in,
        },
        {
            key: 'recurring-out',
            label: __('Recurring charges'),
            amount: projection.drivers.recurring_out,
        },
        {
            // Shown so the breakdown reconciles. Without it the recurring
            // figure looks like an unexplained hole: the transfer leaves as an
            // outflow and comes back as wealth, and only one half is visible.
            key: 'contributions',
            label: __('Moved into savings'),
            amount: projection.drivers.contributions,
        },
        {
            key: 'everyday',
            label: __('Everyday spending'),
            amount: projection.drivers.everyday_spending,
        },
    ].filter((driver) => driver.amount !== null && driver.amount !== 0);

    return (
        <Card>
            <CardHeader className="gap-1">
                <CardTitle className="text-base">
                    {__('Where this is heading')}
                </CardTitle>
                <CardDescription>
                    {__(
                        'Your net worth in :months months if nothing changes.',
                        { months: String(projection.months) },
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="flex flex-col gap-1">
                        <span className="text-xs text-muted-foreground">
                            {__('Today')}
                        </span>
                        <AmountDisplay
                            amountInCents={projection.today}
                            currencyCode={projection.currency_code}
                            size="lg"
                            weight="semibold"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="text-xs text-muted-foreground">
                            {__('Committed')}
                        </span>
                        <AmountDisplay
                            amountInCents={projection.committed}
                            currencyCode={projection.currency_code}
                            size="lg"
                            weight="semibold"
                        />
                        <span className="text-xs text-muted-foreground">
                            {__('Loans, recurring charges, revaluation')}
                        </span>
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="text-xs text-muted-foreground">
                            {__('With your usual spending')}
                        </span>
                        {projection.estimated === null ? (
                            <span className="text-sm text-muted-foreground">
                                {__('Not enough history yet')}
                            </span>
                        ) : (
                            <>
                                <AmountDisplay
                                    amountInCents={projection.estimated}
                                    currencyCode={projection.currency_code}
                                    size="lg"
                                    weight="semibold"
                                    className="text-muted-foreground"
                                />
                                <span className="text-xs text-muted-foreground">
                                    {change >= 0 ? '+' : ''}
                                    {money(change)} {__('over the period')}
                                </span>
                            </>
                        )}
                    </div>
                </div>

                <ChartContainer
                    config={chartConfig}
                    className="aspect-auto h-[260px] w-full"
                >
                    <ComposedChart
                        data={data}
                        margin={{ left: 4, right: 8, top: 8, bottom: 0 }}
                    >
                        <CartesianGrid vertical={false} strokeDasharray="3 3" />
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={24}
                            tickFormatter={monthLabel}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            width={64}
                            domain={[low - pad, high + pad]}
                            tickFormatter={compact}
                        />

                        {today && (
                            <ReferenceLine
                                x={today.date}
                                stroke="var(--color-muted-foreground)"
                                strokeDasharray="4 4"
                                label={{
                                    value: __('Today'),
                                    position: 'insideTopLeft',
                                    fill: 'var(--color-muted-foreground)',
                                    fontSize: 11,
                                }}
                            />
                        )}

                        <Area
                            dataKey="range"
                            stroke="none"
                            fill="var(--color-chart-3)"
                            fillOpacity={0.14}
                            isAnimationActive={false}
                            connectNulls
                        />
                        <Line
                            dataKey="actual"
                            stroke="var(--color-chart-1)"
                            strokeWidth={2}
                            dot={false}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="committed"
                            stroke="var(--color-chart-1)"
                            strokeWidth={2}
                            strokeDasharray="6 4"
                            dot={false}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="estimated"
                            stroke="var(--color-chart-3)"
                            strokeWidth={2}
                            strokeDasharray="2 4"
                            dot={false}
                            isAnimationActive={false}
                        />

                        <ChartTooltip
                            content={({ active, payload, label }) => {
                                if (!active || !payload?.length) return null;

                                return (
                                    <div className="rounded-lg border bg-background p-2 text-xs shadow-xs">
                                        <div className="mb-1 font-medium">
                                            {new Intl.DateTimeFormat(locale, {
                                                month: 'long',
                                                year: 'numeric',
                                            }).format(
                                                new Date(
                                                    `${String(label)}T00:00:00`,
                                                ),
                                            )}
                                        </div>
                                        {payload
                                            .filter(
                                                (entry) =>
                                                    entry.dataKey !== 'range' &&
                                                    entry.value !== null,
                                            )
                                            .map((entry) => (
                                                <div
                                                    key={String(entry.dataKey)}
                                                    className="flex items-center justify-between gap-4"
                                                >
                                                    <span className="text-muted-foreground">
                                                        {
                                                            chartConfig[
                                                                entry.dataKey as keyof typeof chartConfig
                                                            ]?.label
                                                        }
                                                    </span>
                                                    <span className="font-medium tabular-nums">
                                                        {money(
                                                            Number(entry.value),
                                                        )}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                );
                            }}
                        />
                    </ComposedChart>
                </ChartContainer>

                <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
                    {legend.map((item) => (
                        <span
                            key={item.key}
                            className="flex items-center gap-1.5"
                        >
                            <svg width="20" height="2" aria-hidden="true">
                                <line
                                    x1="0"
                                    y1="1"
                                    x2="20"
                                    y2="1"
                                    stroke={item.color}
                                    strokeWidth="2"
                                    strokeDasharray={item.dash}
                                />
                            </svg>
                            {item.label}
                        </span>
                    ))}
                </div>

                {drivers.length > 0 && (
                    <div className="flex flex-wrap gap-x-5 gap-y-2 border-t pt-4 text-xs">
                        {drivers.map((driver) => (
                            <div
                                key={driver.key}
                                className="flex flex-col gap-0.5"
                            >
                                <span className="text-muted-foreground">
                                    {driver.label}
                                </span>
                                <span
                                    className={cn(
                                        'font-medium tabular-nums',
                                        (driver.amount ?? 0) < 0 &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {(driver.amount ?? 0) >= 0 ? '+' : ''}
                                    {money(driver.amount ?? 0)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    {projection.assumptions.recurring_income &&
                        __(
                            'The committed figure assumes your recurring income keeps arriving — it is also what pays the loans down.',
                        )}{' '}
                    {projection.assumptions.investments_flat &&
                        __(
                            'Investments and pensions are held at today’s value — no return is assumed.',
                        )}{' '}
                    {projection.assumptions.revalued_accounts.length > 0 &&
                        __(':accounts revalues at the rate you set.', {
                            accounts:
                                projection.assumptions.revalued_accounts.join(
                                    ', ',
                                ),
                        })}{' '}
                    {projection.assumptions.months_observed !== null &&
                        __(
                            'Everyday spending is the median of :months complete months.',
                            {
                                months: String(
                                    projection.assumptions.months_observed,
                                ),
                            },
                        )}
                </p>
            </CardContent>
        </Card>
    );
}
