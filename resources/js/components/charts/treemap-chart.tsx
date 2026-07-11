import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyCategory, SankeyData } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo, useState } from 'react';
import { ResponsiveContainer, Tooltip, Treemap } from 'recharts';

interface TreemapChartProps {
    categories: SankeyCategory[];
    total: number;
    mode: 'income' | 'expense';
    height?: number;
    className?: string;
    currency?: string;
    period?: { from: Date; to: Date };
}

interface TreemapDatum {
    name: string;
    size: number;
    color: string;
    categoryId: string;
    children?: TreemapDatum[];
    // Satisfies recharts' TreemapDataType index signature.
    [key: string]: unknown;
}

// Recharts merges each datum's fields into the node passed to `content`/`onClick`.
interface TreemapNodeProps extends Partial<TreemapDatum> {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
    depth?: number;
    currency: string;
    locale: string;
    isPrivacyModeEnabled: boolean;
}

function maskIfPrivate(
    value: number,
    currency: string,
    locale: string,
    isPrivacyModeEnabled: boolean,
): string {
    const formatted = formatCurrency(value, currency, locale, 0, 0);
    return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
}

export function truncate(name: string, width: number): string {
    const maxChars = Math.floor((width - 12) / 7);

    if (name.length <= maxChars) {
        return name;
    }

    return `${name.slice(0, Math.max(0, maxChars - 1))}…`;
}

function CategoryNode({
    x = 0,
    y = 0,
    width = 0,
    height = 0,
    depth = 0,
    name = '',
    size = 0,
    color = 'var(--color-chart-4)',
    categoryId,
    children,
    currency,
    locale,
    isPrivacyModeEnabled,
}: TreemapNodeProps) {
    // depth 0 is the invisible root; only leaves/parents carry a category.
    if (depth < 1 || !categoryId) {
        return null;
    }

    const showName = width >= 40 && height >= 22;
    const showAmount = width >= 55 && height >= 40;
    const hasChildren = Array.isArray(children) && children.length > 0;

    return (
        <g style={{ cursor: 'pointer' }}>
            <rect
                x={x}
                y={y}
                width={width}
                height={height}
                rx={4}
                fill={color}
                fillOpacity={0.85}
                stroke="var(--color-background)"
                strokeWidth={2}
            />
            {showName && (
                <text
                    x={x + 8}
                    y={y + 18}
                    className="fill-white text-[12px] font-medium"
                >
                    {truncate(hasChildren ? `${name} ›` : name, width)}
                </text>
            )}
            {showAmount && (
                <text
                    x={x + 8}
                    y={y + 34}
                    className="fill-white/80 text-[11px] tabular-nums"
                >
                    {maskIfPrivate(
                        size,
                        currency,
                        locale,
                        isPrivacyModeEnabled,
                    )}
                </text>
            )}
        </g>
    );
}

interface TreemapTooltipProps {
    active?: boolean;
    payload?: Array<{ payload?: TreemapDatum }>;
    currency: string;
    locale: string;
    isPrivacyModeEnabled: boolean;
}

function TreemapTooltip({
    active,
    payload,
    currency,
    locale,
    isPrivacyModeEnabled,
}: TreemapTooltipProps) {
    const node = payload?.[0]?.payload;

    if (!active || !node?.name) {
        return null;
    }

    return (
        <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-xs shadow-xl">
            <div className="font-medium">{node.name}</div>
            <div className="text-muted-foreground tabular-nums">
                {maskIfPrivate(
                    node.size,
                    currency,
                    locale,
                    isPrivacyModeEnabled,
                )}
            </div>
        </div>
    );
}

export function TreemapChart({
    categories,
    total,
    mode,
    height = 400,
    className,
    currency = 'USD',
    period,
}: TreemapChartProps) {
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const { categoryBarColor } = useChartColors();

    // Nest mode needs the full tree upfront, so prefetch each parent's children.
    // ponytail: one level deep — matches the old sankey; deeper nesting would
    // need recursive fetching and rollUp already caps categories at depth 3.
    const [childrenByParent, setChildrenByParent] = useState<
        Record<string, SankeyCategory[]>
    >({});

    const periodKey = period
        ? `${period.from.getTime()}-${period.to.getTime()}`
        : '';

    useEffect(() => {
        if (!period) {
            return;
        }

        const parents = categories.filter(
            (item) => item.has_children && item.category_id,
        );

        if (parents.length === 0) {
            setChildrenByParent({});
            return;
        }

        const from = format(period.from, 'yyyy-MM-dd');
        const to = format(period.to, 'yyyy-MM-dd');
        let cancelled = false;

        Promise.all(
            parents.map(async (parent) => {
                const response = await fetch(
                    `/api/cashflow/sankey?from=${from}&to=${to}&parent=${parent.category_id}`,
                );
                const json: SankeyData = await response.json();
                const kids =
                    mode === 'income'
                        ? json.income_categories
                        : json.expense_categories;

                return [parent.category_id, kids] as const;
            }),
        )
            .then((entries) => {
                if (cancelled) {
                    return;
                }

                const next: Record<string, SankeyCategory[]> = {};
                entries.forEach(([id, kids]) => {
                    next[id] = kids;
                });
                setChildrenByParent(next);
            })
            .catch((error) => {
                console.error('Failed to fetch subcategories:', error);
            });

        return () => {
            cancelled = true;
        };
        // categoryBarColor is intentionally excluded: it changes identity every
        // render and colours are applied in the memo below, not here.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [categories, periodKey, mode]);

    const data = useMemo<TreemapDatum[]>(() => {
        const toDatum = (
            item: SankeyCategory,
            index: number,
        ): TreemapDatum => ({
            name: item.category.name,
            size: item.amount,
            color: categoryBarColor(item.category.color, index),
            categoryId: item.category_id ?? '',
        });

        const sortPositive = (items: SankeyCategory[]): SankeyCategory[] =>
            [...items]
                .filter((item) => item.amount > 0)
                .sort((a, b) => b.amount - a.amount);

        return sortPositive(categories).map((item, index) => {
            const datum = toDatum(item, index);
            const kids = item.category_id
                ? childrenByParent[item.category_id]
                : undefined;

            if (kids && kids.length > 0) {
                datum.children = sortPositive(kids).map(toDatum);
            }

            return datum;
        });
    }, [categories, childrenByParent, categoryBarColor]);

    if (total === 0 || data.length === 0) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center text-muted-foreground',
                    className,
                )}
                style={{ height }}
            >
                {__('No cashflow data for this period')}
            </div>
        );
    }

    const navigate = (categoryId: unknown) => {
        if (!categoryId || typeof categoryId !== 'string' || !period) {
            return;
        }

        router.visit(
            transactionsIndex({
                query: {
                    category_ids: categoryId,
                    date_from: format(period.from, 'yyyy-MM-dd'),
                    date_to: format(period.to, 'yyyy-MM-dd'),
                },
            }).url,
        );
    };

    return (
        <div
            className={cn(
                'w-full',
                // Recharts renders the nest breadcrumb with hardcoded inline
                // styles; override them to match the design system.
                '[&_.recharts-treemap-nest-index-box]:!mr-1 [&_.recharts-treemap-nest-index-box]:!rounded [&_.recharts-treemap-nest-index-box]:!bg-muted [&_.recharts-treemap-nest-index-box]:!px-2 [&_.recharts-treemap-nest-index-box]:!text-foreground',
                className,
            )}
        >
            <ResponsiveContainer width="100%" height={height}>
                <Treemap
                    data={data}
                    dataKey="size"
                    type="nest"
                    nodeGap={2}
                    // ponytail: key by mode so a toggle switch re-lays out cleanly
                    key={mode}
                    isAnimationActive={false}
                    // In nest mode onClick fires on every node; parents zoom in,
                    // so only leaves navigate to their transactions.
                    onClick={(node) => {
                        if (!node.children) {
                            navigate(node.categoryId);
                        }
                    }}
                    content={
                        <CategoryNode
                            currency={currency}
                            locale={locale}
                            isPrivacyModeEnabled={isPrivacyModeEnabled}
                        />
                    }
                >
                    <Tooltip
                        content={
                            <TreemapTooltip
                                currency={currency}
                                locale={locale}
                                isPrivacyModeEnabled={isPrivacyModeEnabled}
                            />
                        }
                    />
                </Treemap>
            </ResponsiveContainer>
        </div>
    );
}
