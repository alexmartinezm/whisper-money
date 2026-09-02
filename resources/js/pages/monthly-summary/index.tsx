import {
    analyse,
    index,
    store,
} from '@/actions/App/Http/Controllers/MonthlySummaryController';
import HeadingSmall from '@/components/heading-small';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { Lock, Sparkles } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Month in review', href: index().url },
];

interface CategoryRow {
    name: string;
    amount: number;
    share: number;
    change_percent: number;
}

interface Payload {
    currency: string;
    has_history: boolean;
    net_worth: { current: number; diff: number; diff_percent: number };
    cashflow: {
        income: number;
        expense: number;
        net: number;
        savings_rate: number;
        expense_change_percent: number;
    };
    categories: { total: number; top: CategoryRow[] };
    budgets: { total: number; met: number };
    todos: { uncategorised: { count: number; amount: number } };
}

interface Summary {
    id: string;
    period: string;
    payload: Payload;
    complete: boolean;
    analysis: string | null;
}

interface Props {
    period: string;
    summary: Summary | null;
    canAnalyse: boolean;
}

/** "2026-08" as the reader's locale writes it. */
function monthLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}

function percent(value: number): string {
    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}

export default function MonthlySummaryPage({
    period,
    summary,
    canAnalyse,
}: Props) {
    const [working, setWorking] = useState(false);

    const generate = () => {
        setWorking(true);
        router.post(
            store().url,
            { period },
            { onFinish: () => setWorking(false) },
        );
    };

    const requestAnalysis = () => {
        if (!summary) {
            return;
        }

        setWorking(true);
        router.post(
            analyse(summary.id).url,
            {},
            { onFinish: () => setWorking(false) },
        );
    };

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Month in review')} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <HeadingSmall
                        title={monthLabel(period)}
                        description={__(
                            'A reading of one closed month. The figures are frozen the first time you ask, so the month keeps saying the same thing.',
                        )}
                    />
                    {summary && !summary.complete && (
                        <Badge variant="secondary">
                            {__('Still settling')}
                        </Badge>
                    )}
                </div>

                {summary === null ? (
                    <Card>
                        <CardContent className="flex flex-col items-start gap-4 p-6">
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'This month has not been analysed yet. Nothing is computed until you ask.',
                                )}
                            </p>
                            <Button onClick={generate} disabled={working}>
                                {__('Analyse this month')}
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <MonthReport
                        summary={summary}
                        canAnalyse={canAnalyse}
                        working={working}
                        onAnalyse={requestAnalysis}
                    />
                )}
            </div>
        </AppSidebarLayout>
    );
}

function MonthReport({
    summary,
    canAnalyse,
    working,
    onAnalyse,
}: {
    summary: Summary;
    canAnalyse: boolean;
    working: boolean;
    onAnalyse: () => void;
}) {
    const { payload } = summary;
    const currency = payload.currency;

    return (
        <div className="flex flex-col gap-4">
            {summary.analysis ? (
                <Card>
                    <CardContent className="flex flex-col gap-3 p-6">
                        {summary.analysis
                            .split('\n')
                            .filter((line) => line.trim() !== '')
                            .map((paragraph) => (
                                <p key={paragraph} className="text-sm">
                                    {paragraph}
                                </p>
                            ))}
                        <p className="text-xs text-muted-foreground">
                            {__(
                                'Written from the figures below. It never sees individual transactions or merchants.',
                            )}
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="flex flex-col items-start gap-3 p-6">
                        {canAnalyse ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Ask for a short written read of what moved this month, and what is going to repeat.',
                                    )}
                                </p>
                                <Button
                                    variant="outline"
                                    onClick={onAnalyse}
                                    disabled={working}
                                >
                                    <Sparkles />
                                    {__('Explain this month')}
                                </Button>
                            </>
                        ) : (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Lock className="size-3.5" />
                                {__(
                                    'A written analysis needs a paid plan and AI enabled.',
                                )}
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Figure
                    label={__('Income')}
                    amount={payload.cashflow.income}
                    currency={currency}
                />
                <Figure
                    label={__('Spent')}
                    amount={payload.cashflow.expense}
                    currency={currency}
                    note={
                        payload.has_history
                            ? __('vs last month: :change', {
                                  change: percent(
                                      payload.cashflow.expense_change_percent,
                                  ),
                              })
                            : undefined
                    }
                />
                <Figure
                    label={__('Net')}
                    amount={payload.cashflow.net}
                    currency={currency}
                    note={__('Savings rate: :rate', {
                        rate: `${payload.cashflow.savings_rate.toFixed(1)}%`,
                    })}
                />
                <Figure
                    label={__('Net worth')}
                    amount={payload.net_worth.current}
                    currency={currency}
                    note={
                        payload.has_history
                            ? percent(payload.net_worth.diff_percent)
                            : undefined
                    }
                />
            </div>

            {payload.categories.top.length > 0 && (
                <Card>
                    <CardContent className="flex flex-col gap-3 p-6">
                        <h3 className="text-sm font-semibold">
                            {__('Where it went')}
                        </h3>
                        <div className="flex flex-col gap-2">
                            {payload.categories.top.map((row) => (
                                <div
                                    key={row.name}
                                    className="flex items-center justify-between gap-4 text-sm"
                                >
                                    <span className="min-w-0 truncate">
                                        {row.name}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-3 tabular-nums">
                                        <span className="text-muted-foreground">
                                            {row.share.toFixed(0)}%
                                        </span>
                                        <AmountDisplay
                                            amountInCents={row.amount}
                                            currencyCode={currency}
                                        />
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {payload.todos.uncategorised.count > 0 && (
                <Card>
                    <CardContent className="p-6 text-sm text-muted-foreground">
                        {__(
                            ':count transactions are still uncategorised, worth :amount.',
                            {
                                count: String(
                                    payload.todos.uncategorised.count,
                                ),
                                amount: new Intl.NumberFormat(undefined, {
                                    style: 'currency',
                                    currency,
                                }).format(
                                    payload.todos.uncategorised.amount / 100,
                                ),
                            },
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function Figure({
    label,
    amount,
    currency,
    note,
}: {
    label: string;
    amount: number;
    currency: string;
    note?: string;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-1 p-4">
                <span className="text-xs text-muted-foreground">{label}</span>
                <AmountDisplay
                    amountInCents={amount}
                    currencyCode={currency}
                    size="xl"
                    weight="semibold"
                />
                {note && (
                    <span className="text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </CardContent>
        </Card>
    );
}
