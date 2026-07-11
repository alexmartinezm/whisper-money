import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyData } from '@/hooks/use-cashflow-data';
import { useLocale } from '@/hooks/use-locale';
import { buildDonutRings, DonutRing, DonutSegment } from '@/lib/donut-utils';
import { cn } from '@/lib/utils';
import { getCategoryChartColor } from '@/types/category';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

interface MultiLevelDonutProps {
    data: SankeyData;
    showIncome: boolean;
    height?: number;
    className?: string;
    currency?: string;
    period?: { from: Date; to: Date };
}

const RAD = Math.PI / 180;
// Radii as a fraction of the chart radius (half the smaller dimension).
const HOLE_FRACTION = 0.3;
const RIM_FRACTION = 0.6;
const RING_GAP = 2;
// Minimum arc (degrees) a slice must span to earn a label.
const MIN_INSIDE_DEG = 18;
const MIN_OUTER_DEG = 4;
// Outer leader-line label layout.
const LEADER_ELBOW = 12;
const LEADER_RUN = 12;
const LABEL_GAP = 14;
const MAX_LABEL_CHARS = 18;
// Below this width the leader labels don't fit; the outer ring is read on tap.
const LEADER_MIN_WIDTH = 480;

function segmentColor(segment: DonutSegment): string {
    return getCategoryChartColor(segment.color ?? 'gray');
}

function truncate(name: string): string {
    return name.length > MAX_LABEL_CHARS
        ? `${name.slice(0, MAX_LABEL_CHARS - 1)}…`
        : name;
}

interface OuterLabel {
    key: string;
    side: 1 | -1;
    edgeX: number;
    edgeY: number;
    y: number;
    name: string;
    percentage: number;
}

/**
 * Position the outermost ring's labels outside the donut with a leader line,
 * pushing them apart vertically per side so thin slices stay readable without
 * overlapping (Highcharts pie-donut style).
 */
function layoutOuterLabels(
    ring: DonutRing,
    cx: number,
    cy: number,
    radius: number,
    height: number,
): OuterLabel[] {
    if (ring.total <= 0) {
        return [];
    }

    let cursor = 0;
    const labels: OuterLabel[] = [];

    for (const segment of ring.segments) {
        const midFraction = (cursor + segment.value / 2) / ring.total;
        cursor += segment.value;

        if ((segment.value / ring.total) * 360 < MIN_OUTER_DEG) {
            continue;
        }

        const angle = -(90 - midFraction * 360) * RAD;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);

        labels.push({
            key: segment.key,
            side: cos >= 0 ? 1 : -1,
            edgeX: cx + radius * cos,
            edgeY: cy + radius * sin,
            y: cy + (radius + LEADER_ELBOW) * sin,
            name: truncate(segment.name),
            percentage: (segment.value / ring.total) * 100,
        });
    }

    for (const side of [1, -1] as const) {
        const column = labels
            .filter((label) => label.side === side)
            .sort((a, b) => a.y - b.y);

        let previous = -Infinity;
        for (const label of column) {
            label.y = Math.max(label.y, previous + LABEL_GAP);
            previous = label.y;
        }

        const bottom = height - 6;
        if (column.length > 0 && column[column.length - 1].y > bottom) {
            let next = bottom;
            for (let i = column.length - 1; i >= 0; i--) {
                column[i].y = Math.min(column[i].y, next);
                next = column[i].y - LABEL_GAP;
            }
        }
    }

    return labels;
}

interface PieLabelProps {
    cx?: number;
    cy?: number;
    midAngle?: number;
    innerRadius?: number;
    outerRadius?: number;
    percent?: number;
    name?: string | number;
}

/** Inside label for the innermost ring's wide-enough slices. */
function InsideLabel({
    cx = 0,
    cy = 0,
    midAngle = 0,
    innerRadius = 0,
    outerRadius = 0,
    percent = 0,
    name = '',
}: PieLabelProps) {
    if (percent * 360 < MIN_INSIDE_DEG) {
        return null;
    }

    const r = (innerRadius + outerRadius) / 2;
    const angle = -midAngle * RAD;

    return (
        <text
            x={cx + r * Math.cos(angle)}
            y={cy + r * Math.sin(angle)}
            textAnchor="middle"
            dominantBaseline="central"
            fontSize={10}
            fontWeight={600}
            fill="#fff"
            style={{
                paintOrder: 'stroke',
                stroke: 'rgba(0,0,0,0.45)',
                strokeWidth: 2.5,
            }}
        >
            {truncate(String(name))}
        </text>
    );
}

interface DonutTooltipProps {
    active?: boolean;
    payload?: { payload?: DonutSegment }[];
    totalIncome: number;
    totalExpense: number;
    mask: (value: number) => string;
}

function DonutTooltip({
    active,
    payload,
    totalIncome,
    totalExpense,
    mask,
}: DonutTooltipProps) {
    const segment = active ? payload?.[0]?.payload : undefined;

    if (!segment) {
        return null;
    }

    const directionTotal =
        segment.direction === 'income' ? totalIncome : totalExpense;
    const percentage =
        directionTotal > 0 ? (segment.value / directionTotal) * 100 : 0;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-xs shadow-xl">
            <div className="flex items-center gap-2 font-medium">
                <span
                    className="size-2 rounded-full"
                    style={{ background: segmentColor(segment) }}
                />
                {segment.name}
            </div>
            <div className="mt-1 flex items-center justify-between gap-4 tabular-nums">
                <span className="font-mono font-medium">
                    {mask(segment.value)}
                </span>
                <span className="text-muted-foreground">
                    {percentage.toFixed(1)}%
                </span>
            </div>
        </div>
    );
}

export function MultiLevelDonut({
    data,
    showIncome,
    height = 400,
    className,
    currency = 'USD',
    period,
}: MultiLevelDonutProps) {
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const containerRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const container = containerRef.current;

        if (!container || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() =>
            setWidth(container.clientWidth),
        );
        observer.observe(container);

        return () => observer.disconnect();
    }, []);

    const rings = useMemo<DonutRing[]>(
        () =>
            buildDonutRings(data.income_categories, data.expense_categories, {
                showIncome,
            }),
        [data.income_categories, data.expense_categories, showIncome],
    );

    const geometry = useMemo(() => {
        const base = Math.min(height, width || height);
        const chartRadius = base / 2;
        const holeRadius = chartRadius * HOLE_FRACTION;
        const rimRadius = chartRadius * RIM_FRACTION;
        const band =
            rings.length > 0 ? (rimRadius - holeRadius) / rings.length : 0;

        return {
            cx: (width || height) / 2,
            cy: height / 2,
            holeRadius,
            band,
            rimRadius,
        };
    }, [width, height, rings.length]);

    const outerLabels = useMemo(() => {
        if (rings.length === 0 || width < LEADER_MIN_WIDTH) {
            return [];
        }

        return layoutOuterLabels(
            rings[rings.length - 1],
            geometry.cx,
            geometry.cy,
            geometry.rimRadius - RING_GAP,
            height,
        );
    }, [rings, geometry, width, height]);

    const mask = (value: number) => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
    };

    if (rings.length === 0) {
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

    const handleClick = (segment: DonutSegment) => {
        if (!segment.categoryId || !period) {
            return;
        }

        router.visit(
            transactionsIndex({
                query: {
                    category_ids: segment.categoryId,
                    date_from: format(period.from, 'yyyy-MM-dd'),
                    date_to: format(period.to, 'yyyy-MM-dd'),
                },
            }).url,
        );
    };

    const centerLabel = showIncome ? __('Net') : __('Expenses');
    const centerValue = showIncome
        ? data.total_income - data.total_expense
        : data.total_expense;

    return (
        <div
            ref={containerRef}
            className={cn('relative w-full', className)}
            style={{ height }}
        >
            <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                    <Tooltip
                        wrapperStyle={{ zIndex: 40 }}
                        content={
                            <DonutTooltip
                                totalIncome={data.total_income}
                                totalExpense={data.total_expense}
                                mask={mask}
                            />
                        }
                    />
                    {rings.map((ring, index) => (
                        <Pie
                            key={`${ring.direction}-${ring.level}`}
                            data={ring.segments}
                            dataKey="value"
                            nameKey="name"
                            cx="50%"
                            cy="50%"
                            innerRadius={
                                geometry.holeRadius + index * geometry.band
                            }
                            outerRadius={
                                geometry.holeRadius +
                                (index + 1) * geometry.band -
                                RING_GAP
                            }
                            startAngle={90}
                            endAngle={-270}
                            paddingAngle={0}
                            stroke="var(--color-background)"
                            strokeWidth={1}
                            isAnimationActive={false}
                            labelLine={false}
                            label={index === 0 ? InsideLabel : false}
                            onClick={(entry) => {
                                const withPayload = entry as {
                                    payload?: DonutSegment;
                                };
                                handleClick(
                                    withPayload.payload ??
                                        (entry as unknown as DonutSegment),
                                );
                            }}
                        >
                            {ring.segments.map((segment) => (
                                <Cell
                                    key={segment.key}
                                    fill={segmentColor(segment)}
                                    className={cn(
                                        segment.categoryId && 'cursor-pointer',
                                    )}
                                />
                            ))}
                        </Pie>
                    ))}
                </PieChart>
            </ResponsiveContainer>

            {outerLabels.length > 0 && (
                <svg
                    className="pointer-events-none absolute inset-0"
                    width={width}
                    height={height}
                >
                    {outerLabels.map((label) => {
                        const elbowX =
                            geometry.cx + label.side * (geometry.rimRadius + 2);
                        const textX = elbowX + label.side * LEADER_RUN;

                        return (
                            <g key={label.key}>
                                <polyline
                                    points={`${label.edgeX},${label.edgeY} ${elbowX},${label.y} ${textX},${label.y}`}
                                    fill="none"
                                    stroke="var(--color-border)"
                                    strokeWidth={1}
                                />
                                <text
                                    x={textX + label.side * 3}
                                    y={label.y}
                                    textAnchor={
                                        label.side > 0 ? 'start' : 'end'
                                    }
                                    dominantBaseline="central"
                                    fontSize={10}
                                    fill="var(--color-foreground)"
                                >
                                    {label.name}
                                    <tspan fill="var(--color-muted-foreground)">
                                        {' '}
                                        {label.percentage.toFixed(1)}%
                                    </tspan>
                                </text>
                            </g>
                        );
                    })}
                </svg>
            )}

            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                <span className="text-xs text-muted-foreground">
                    {centerLabel}
                </span>
                <span className="font-mono text-sm font-semibold tabular-nums">
                    {mask(centerValue)}
                </span>
            </div>
        </div>
    );
}
