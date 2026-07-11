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
import { ChevronRight } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
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
    hasChildren: boolean;
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
    hasChildren = false,
    currency,
    locale,
    isPrivacyModeEnabled,
}: TreemapNodeProps) {
    // depth 0 is the invisible root; only leaves carry a category.
    if (depth < 1 || !categoryId) {
        return null;
    }

    const showName = width >= 40 && height >= 22;
    const showAmount = width >= 55 && height >= 40;

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

    // Self-controlled drill-down: recharts' native "nest" breadcrumb can't
    // offer a way back to the root, so we keep our own path + children cache
    // and render a flat treemap per level.
    const [path, setPath] = useState<Array<{ id: string; name: string }>>([]);
    const [childrenById, setChildrenById] = useState<
        Record<string, SankeyCategory[]>
    >({});

    const periodKey = period
        ? `${period.from.getTime()}-${period.to.getTime()}`
        : '';

    useEffect(() => {
        setPath([]);
        setChildrenById({});
    }, [periodKey, mode]);

    const data = useMemo<TreemapDatum[]>(() => {
        const current =
            path.length === 0
                ? categories
                : (childrenById[path[path.length - 1].id] ?? []);

        return [...current]
            .filter((item) => item.amount > 0)
            .sort((a, b) => b.amount - a.amount)
            .map((item, index) => ({
                name: item.category.name,
                size: item.amount,
                color: categoryBarColor(item.category.color, index),
                categoryId: item.category_id ?? '',
                hasChildren: !!item.has_children,
            }));
    }, [categories, childrenById, path, categoryBarColor]);

    if (total === 0 || categories.length === 0) {
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

    const drillInto = async (categoryId: string, name: string) => {
        if (!period) {
            return;
        }

        if (!(categoryId in childrenById)) {
            try {
                const from = format(period.from, 'yyyy-MM-dd');
                const to = format(period.to, 'yyyy-MM-dd');
                const response = await fetch(
                    `/api/cashflow/sankey?from=${from}&to=${to}&parent=${categoryId}`,
                );
                const json: SankeyData = await response.json();
                const kids =
                    mode === 'income'
                        ? json.income_categories
                        : json.expense_categories;
                setChildrenById((previous) => ({
                    ...previous,
                    [categoryId]: kids,
                }));
            } catch (error) {
                console.error('Failed to fetch subcategories:', error);
                return;
            }
        }

        setPath((previous) => [...previous, { id: categoryId, name }]);
    };

    const navigate = (categoryId: string) => {
        if (!categoryId || !period) {
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

    const rootLabel = mode === 'income' ? __('Income') : __('Expenses');

    return (
        <div className={cn('w-full', className)}>
            {path.length > 0 && (
                <nav
                    aria-label={__('Breadcrumb')}
                    className="mb-3 flex flex-wrap items-center gap-1 text-sm"
                >
                    <button
                        type="button"
                        onClick={() => setPath([])}
                        className="cursor-pointer font-medium text-muted-foreground hover:text-foreground"
                    >
                        {rootLabel}
                    </button>
                    {path.map((item, index) => {
                        const isLast = index === path.length - 1;

                        return (
                            <Fragment key={item.id}>
                                <ChevronRight className="size-3.5 text-muted-foreground" />
                                {isLast ? (
                                    <span className="font-medium text-foreground">
                                        {item.name}
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setPath(path.slice(0, index + 1))
                                        }
                                        className="cursor-pointer text-muted-foreground hover:text-foreground"
                                    >
                                        {item.name}
                                    </button>
                                )}
                            </Fragment>
                        );
                    })}
                </nav>
            )}

            <ResponsiveContainer width="100%" height={height}>
                <Treemap
                    data={data}
                    dataKey="size"
                    nodeGap={2}
                    // ponytail: key by level so switching mode/level re-lays out cleanly
                    key={`${mode}-${path.length}`}
                    isAnimationActive={false}
                    onClick={(node) => {
                        const id = node.categoryId;

                        if (typeof id !== 'string' || !id) {
                            return;
                        }

                        if (node.hasChildren) {
                            drillInto(id, String(node.name ?? ''));
                        } else {
                            navigate(id);
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
