import { SankeyCategory } from '@/hooks/use-cashflow-data';
import { groupSmallCategories } from '@/lib/sankey-utils';
import { CategoryColor } from '@/types/category';
import { __ } from '@/utils/i18n';

export type DonutDirection = 'income' | 'expense';

export interface DonutSegment {
    /** Stable identity for React keys and cross-ring arc alignment. */
    key: string;
    name: string;
    value: number;
    /** Real category id to link to, or null when not navigable. */
    categoryId: string | null;
    color: CategoryColor | null;
    isDirect: boolean;
    /** 1-based depth of the resolved node in its tree. */
    depth: number;
    direction: DonutDirection;
}

export interface DonutRing {
    /** 1-based level of the tree this ring renders. */
    level: number;
    direction: DonutDirection;
    /** Sum of the segment values; equals the direction total for every ring. */
    total: number;
    segments: DonutSegment[];
}

interface Leaf {
    path: SankeyCategory[];
    value: number;
}

const DEFAULT_THRESHOLD = 0.03;

function nodeId(node: SankeyCategory): string {
    if (node.is_direct) {
        return `${node.category_id}:direct`;
    }

    return node.category_id ?? `unknown:${node.category.name}`;
}

/**
 * Fold small sibling categories into a synthetic "Other" node at every level,
 * so no ring drowns in unreadable slivers. Mirrors the sankey grouping.
 */
function groupTree(
    nodes: SankeyCategory[],
    total: number,
    threshold: number,
    keyPrefix: string,
): SankeyCategory[] {
    const { main, other } = groupSmallCategories(nodes, total, threshold);

    const grouped = main.map(
        (node): SankeyCategory =>
            node.children && node.children.length > 0
                ? {
                      ...node,
                      children: groupTree(
                          node.children,
                          node.amount,
                          threshold,
                          `${keyPrefix}/${nodeId(node)}`,
                      ),
                  }
                : node,
    );

    if (other) {
        grouped.push({
            category: {
                ...main[0].category,
                name: __('Other'),
                color: 'gray',
                icon: 'HelpCircle',
            },
            category_id: `${keyPrefix}/other`,
            amount: other.total,
            is_direct: false,
            synthetic: true,
        });
    }

    return grouped;
}

function collectLeaves(
    nodes: SankeyCategory[],
    prefix: SankeyCategory[] = [],
): Leaf[] {
    const leaves: Leaf[] = [];

    for (const node of nodes) {
        const path = [...prefix, node];

        if (node.children && node.children.length > 0) {
            leaves.push(...collectLeaves(node.children, path));
        } else {
            leaves.push({ path, value: node.amount });
        }
    }

    return leaves;
}

function maxDepth(leaves: Leaf[]): number {
    return leaves.reduce((max, leaf) => Math.max(max, leaf.path.length), 0);
}

/**
 * Build one ring's arcs. A leaf shorter than `level` is rendered as its own
 * terminal node (it visually extends outward); adjacent leaves resolving to the
 * same ancestor merge into a single arc. Every ring sums to the same total, so
 * arcs stay angularly aligned with the rings inside and outside them.
 */
function ringSegments(
    leaves: Leaf[],
    level: number,
    direction: DonutDirection,
): DonutSegment[] {
    const segments: DonutSegment[] = [];

    for (const leaf of leaves) {
        const index = Math.min(level, leaf.path.length) - 1;
        const node = leaf.path[index];
        const key = `${direction}:${level}:${nodeId(node)}`;
        const last = segments[segments.length - 1];

        if (last && last.key === key) {
            last.value += leaf.value;

            continue;
        }

        segments.push({
            key,
            name: node.category.name,
            value: leaf.value,
            categoryId: node.synthetic ? null : node.category_id,
            color: node.category.color ?? null,
            isDirect: !!node.is_direct,
            depth: index + 1,
            direction,
        });
    }

    return segments;
}

function directionRings(
    nodes: SankeyCategory[],
    direction: DonutDirection,
    threshold: number,
): DonutRing[] {
    const total = nodes.reduce((sum, node) => sum + node.amount, 0);

    if (total <= 0) {
        return [];
    }

    const grouped = groupTree(nodes, total, threshold, direction);
    const leaves = collectLeaves(grouped);
    const depth = maxDepth(leaves);
    const rings: DonutRing[] = [];

    for (let level = 1; level <= depth; level++) {
        const segments = ringSegments(leaves, level, direction);

        if (segments.length > 0) {
            rings.push({ level, direction, total, segments });
        }
    }

    return rings;
}

export interface BuildDonutRingsOptions {
    showIncome: boolean;
    threshold?: number;
}

/**
 * Assemble the concentric ring stack, innermost first.
 *
 * Combined: income grows toward the centre (deepest income level innermost),
 * expense grows outward (deepest expense level at the rim). With `showIncome`
 * off only the expense rings remain, its top level innermost.
 */
export function buildDonutRings(
    income: SankeyCategory[],
    expense: SankeyCategory[],
    { showIncome, threshold = DEFAULT_THRESHOLD }: BuildDonutRingsOptions,
): DonutRing[] {
    const rings: DonutRing[] = [];

    if (showIncome) {
        // Reverse so the deepest income level sits innermost.
        rings.push(...directionRings(income, 'income', threshold).reverse());
    }

    rings.push(...directionRings(expense, 'expense', threshold));

    return rings;
}
