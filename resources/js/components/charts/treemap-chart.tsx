import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyCategory } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useMemo } from 'react';
import { ResponsiveContainer, Treemap } from 'recharts';

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
            <title>
                {name}:{' '}
                {maskIfPrivate(size, currency, locale, isPrivacyModeEnabled)}
            </title>
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
                    {truncate(name, width)}
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

    const data = useMemo<TreemapDatum[]>(() => {
        return [...categories]
            .filter((item) => item.amount > 0)
            .sort((a, b) => b.amount - a.amount)
            .map((item, index) => ({
                name: item.category.name,
                size: item.amount,
                color: categoryBarColor(item.category.color, index),
                categoryId: item.category.id,
            }));
    }, [categories, categoryBarColor]);

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
        if (typeof categoryId !== 'string' || !period) {
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
        <div className={cn('w-full', className)}>
            <ResponsiveContainer width="100%" height={height}>
                <Treemap
                    data={data}
                    dataKey="size"
                    // ponytail: key by mode so a toggle switch re-lays out cleanly
                    key={mode}
                    isAnimationActive={false}
                    onClick={(node) => navigate(node.categoryId)}
                    content={
                        <CategoryNode
                            currency={currency}
                            locale={locale}
                            isPrivacyModeEnabled={isPrivacyModeEnabled}
                        />
                    }
                />
            </ResponsiveContainer>
        </div>
    );
}
