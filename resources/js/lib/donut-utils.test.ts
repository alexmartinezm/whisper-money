import { SankeyCategory } from '@/hooks/use-cashflow-data';
import { Category } from '@/types/category';
import { describe, expect, it } from 'vitest';

import { buildDonutRings } from './donut-utils';

function node(
    id: string,
    amount: number,
    children?: SankeyCategory[],
): SankeyCategory {
    return {
        category: {
            id,
            name: id,
            icon: 'HelpCircle',
            color: 'blue',
            type: 'expense',
            cashflow_direction: 'hidden',
            parent_id: null,
        } as Category,
        category_id: id,
        amount,
        children,
    };
}

function direct(parentId: string, amount: number): SankeyCategory {
    return { ...node(parentId, amount), is_direct: true };
}

// Food 180 = groceries 80 (organic 30 + direct 50) + direct 100
const threeLevelExpense: SankeyCategory[] = [
    node('food', 180, [
        node('groceries', 80, [node('organic', 30), direct('groceries', 50)]),
        direct('food', 100),
    ]),
];

describe('buildDonutRings', () => {
    it('renders one ring per level, top level innermost, for expenses only', () => {
        const rings = buildDonutRings([], threeLevelExpense, {
            showIncome: false,
        });

        expect(rings.map((r) => r.level)).toEqual([1, 2, 3]);
        expect(rings.every((r) => r.direction === 'expense')).toBe(true);
        expect(rings[0].segments).toHaveLength(1);
        expect(rings[0].segments[0].name).toBe('food');
        expect(rings[1].segments).toHaveLength(2);
        expect(rings[2].segments).toHaveLength(3);
    });

    it('keeps every ring summing to the direction total (arc alignment)', () => {
        const rings = buildDonutRings([], threeLevelExpense, {
            showIncome: false,
        });

        for (const ring of rings) {
            const sum = ring.segments.reduce((s, seg) => s + seg.value, 0);
            expect(sum).toBe(180);
        }
    });

    it('extends a shallow leaf outward so it fills the deeper rings', () => {
        // rent is a plain leaf; groceries splits into a child.
        const expense = [
            node('rent', 100),
            node('groceries', 200, [node('supermarket', 200)]),
        ];
        const rings = buildDonutRings([], expense, { showIncome: false });

        expect(rings).toHaveLength(2);
        // Rings are ordered by amount desc, so groceries (200) precedes rent
        // (100). On ring 2 groceries resolves to its child; rent extends as
        // itself.
        expect(rings[1].segments.map((s) => s.name)).toEqual([
            'supermarket',
            'rent',
        ]);
        expect(rings[1].segments.reduce((s, seg) => s + seg.value, 0)).toBe(
            300,
        );
    });

    it('nests income toward the centre and expense toward the rim', () => {
        const income = [node('salary', 300), node('bonus', 100)];
        const expense = [node('rent', 200, [node('flat', 200)])];
        const rings = buildDonutRings(income, expense, { showIncome: true });

        expect(rings.map((r) => r.direction)).toEqual([
            'income',
            'expense',
            'expense',
        ]);
        expect(rings[0].total).toBe(400);
        expect(rings[1].total).toBe(200);
    });

    it('folds small siblings into a synthetic non-navigable "Other" slice', () => {
        const expense = [
            node('big1', 500),
            node('big2', 400),
            node('big3', 300),
            node('tiny1', 5),
            node('tiny2', 4),
            node('tiny3', 3),
        ];
        const rings = buildDonutRings([], expense, { showIncome: false });

        const other = rings[0].segments.find((s) => s.name === 'Other');
        expect(other).toBeDefined();
        expect(other?.value).toBe(12);
        expect(other?.categoryId).toBeNull();
        expect(rings[0].segments.reduce((s, seg) => s + seg.value, 0)).toBe(
            1212,
        );
    });
});
