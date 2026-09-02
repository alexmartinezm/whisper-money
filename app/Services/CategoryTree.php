<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CategoryTree
{
    public function lockSubtreeForMutation(Category $category): Category
    {
        $ids = [$category->id, ...$this->descendantIds($category)];

        Transaction::query()
            ->where(fn ($query) => $query
                ->whereIn('category_id', $ids)
                ->orWhereHas('splits', fn ($splits) => $splits->whereIn('category_id', $ids)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return Category::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->firstWhere('id', $category->id)
            ?? throw new ModelNotFoundException;
    }

    /**
     * Expand a set of category ids to also include every descendant id.
     *
     * Used when filtering transactions: selecting a parent must also match
     * transactions assigned to any of its children. Bounded by MAX_DEPTH, so
     * this resolves in at most a couple of cheap queries.
     *
     * Pass `$spaceId` when the caller is already scoped to one space. Nothing
     * stops a category in one space naming a parent in another, so a budget
     * that tracks a parent would otherwise pull in children belonging to a
     * space it has no business reading. It stays opt-in because most callers
     * work across the user's whole tree, and silently narrowing them here would
     * drop subcategories from the dashboard and the cashflow report without a
     * single test noticing.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public function expand(string $userId, array $ids, ?string $spaceId = null): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $collected = $ids;
        $frontier = $ids;

        for ($level = 1; $level < Category::MAX_DEPTH; $level++) {
            $childIds = Category::query()
                ->where('user_id', $userId)
                ->when($spaceId !== null, fn ($query) => $query->where('space_id', $spaceId))
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $childIds = array_values(array_diff($childIds, $collected));

            if ($childIds === []) {
                break;
            }

            $collected = array_merge($collected, $childIds);
            $frontier = $childIds;
        }

        return $collected;
    }

    /**
     * Collect the descendant ids of a single category (excluding itself).
     *
     * @return array<int, string>
     */
    public function descendantIds(Category $category): array
    {
        return array_values(array_diff(
            $this->expand($category->user_id, [$category->id]),
            [$category->id],
        ));
    }

    /**
     * The depth of a category, where a root category is level 1.
     */
    public function depth(Category $category): int
    {
        $depth = 1;
        $parentId = $category->parent_id;

        while ($parentId !== null && $depth <= Category::MAX_DEPTH) {
            $parentId = Category::query()
                ->where('user_id', $category->user_id)
                ->where('id', $parentId)
                ->value('parent_id');

            $depth++;
        }

        return $depth;
    }

    /**
     * The number of levels contained in a category's subtree, including itself
     * (a leaf is 1).
     */
    public function subtreeDepth(Category $category): int
    {
        $depth = 1;
        $frontier = [$category->id];

        for ($level = 1; $level < Category::MAX_DEPTH; $level++) {
            $childIds = Category::query()
                ->where('user_id', $category->user_id)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            if ($childIds === []) {
                break;
            }

            $depth++;
            $frontier = $childIds;
        }

        return $depth;
    }

    /**
     * Roll category amounts up the tree.
     *
     * With no drill target, every amount folds into its top-level ancestor.
     * When drilling into a parent, the parent's children become the nodes (each
     * rolled up over its own subtree) plus a "Parent" node for transactions
     * sitting on the parent itself. Items outside the drilled subtree drop out.
     *
     * @param  array<int, array{category_id: ?string, category: Category|null, amount: int}>  $categorized
     * @return array<int, array{category_id: ?string, category: Category|null, amount: int, has_children: bool, is_direct: bool}>
     */
    public function rollUp(array $categorized, string $userId, ?string $drillParentId): array
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->forDisplay()
            ->get()
            ->keyBy('id');

        $parentMap = $categories->mapWithKeys(fn (Category $category): array => [$category->id => $category->parent_id])->all();
        $childCounts = [];
        foreach ($parentMap as $parentId) {
            if ($parentId !== null) {
                $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
            }
        }

        $nodes = [];
        foreach ($categorized as $item) {
            $categoryId = $item['category_id'];

            if ($categoryId === null || ! array_key_exists($categoryId, $parentMap)) {
                // Uncategorized only belongs in the top-level view.
                if ($drillParentId === null) {
                    $key = 'uncategorized';
                    $nodes[$key] ??= ['category_id' => null, 'category' => $item['category'], 'amount' => 0, 'has_children' => false, 'is_direct' => false];
                    $nodes[$key]['amount'] += $item['amount'];
                }

                continue;
            }

            $target = $this->displayNodeFor($categoryId, $parentMap, $drillParentId);

            if ($target === null) {
                continue;
            }

            $displayCategory = $categories->get($target['id']);

            if ($displayCategory === null) {
                continue;
            }

            if ($target['is_direct']) {
                $key = $target['id'].':direct';
                $category = (new Category)->forceFill([
                    'id' => $displayCategory->id,
                    'name' => __('Parent'),
                    'icon' => $displayCategory->icon,
                    'color' => $displayCategory->color,
                    'type' => $displayCategory->type,
                    'cashflow_direction' => $displayCategory->cashflow_direction,
                    'parent_id' => $displayCategory->parent_id,
                ]);
                $nodes[$key] ??= ['category_id' => $displayCategory->id, 'category' => $category, 'amount' => 0, 'has_children' => false, 'is_direct' => true];
                $nodes[$key]['amount'] += $item['amount'];

                continue;
            }

            $key = $target['id'];
            $nodes[$key] ??= [
                'category_id' => $displayCategory->id,
                'category' => $displayCategory,
                'amount' => 0,
                'has_children' => ($childCounts[$displayCategory->id] ?? 0) > 0,
                'is_direct' => false,
            ];
            $nodes[$key]['amount'] += $item['amount'];
        }

        return array_values($nodes);
    }

    /**
     * Build a two-level spending breakdown: each top-level category with its
     * rolled-up total, and the immediate sub-categories that carry spending
     * nested beneath it. Grand-children fold into their level-2 ancestor;
     * spend sitting directly on a parent that is also split across
     * sub-categories surfaces as a "Direct" child so the children always sum
     * to the parent total. Uncategorized items are left for the caller.
     *
     * Pass $drillParentId to re-root the breakdown at that category: its
     * children become the rows, their own children the nested level, and items
     * outside the subtree drop out. Spend booked on the drilled parent itself
     * has no child to sit under, so it becomes a "Direct" row of its own.
     *
     * @param  array<int, array{category_id: ?string, amount: int}>  $categorized
     * @return array<int, array{category_id: string, category: Category, amount: int, children: array<int, array{category_id: string, category: Category, amount: int}>}>
     */
    public function spendingBreakdown(array $categorized, string $userId, ?string $drillParentId = null): array
    {
        $categories = Category::query()
            ->where('user_id', $userId)
            ->forDisplay()
            ->get()
            ->keyBy('id');

        $parentMap = $categories->mapWithKeys(fn (Category $category): array => [$category->id => $category->parent_id])->all();

        $roots = [];
        foreach ($categorized as $item) {
            $categoryId = $item['category_id'];

            if ($categoryId === null || ! array_key_exists($categoryId, $parentMap)) {
                continue;
            }

            $chain = $this->chainFromRoot($categoryId, $parentMap);
            $offset = $this->chainOffsetAfter($chain, $drillParentId);

            if ($offset === null) {
                continue;
            }

            // Nothing left in the chain means the spend sits on the drilled
            // parent itself, so it gets a "Direct" row instead of a child.
            $isDirectRow = ! isset($chain[$offset]);
            $rowId = $chain[$offset] ?? $drillParentId;
            $key = $isDirectRow ? $rowId.':direct' : $rowId;

            $roots[$key] ??= [
                'category_id' => $rowId,
                'category' => $isDirectRow
                    ? $this->renamedCopy($categories->get($rowId), __('Direct'))
                    : $categories->get($rowId),
                'amount' => 0,
                'direct' => 0,
                'children' => [],
            ];
            $roots[$key]['amount'] += $item['amount'];

            $childId = $chain[$offset + 1] ?? null;

            if ($childId === null) {
                $roots[$key]['direct'] += $item['amount'];

                continue;
            }

            $roots[$key]['children'][$childId] ??= [
                'category_id' => $childId,
                'category' => $categories->get($childId),
                'amount' => 0,
            ];
            $roots[$key]['children'][$childId]['amount'] += $item['amount'];
        }

        return collect($roots)
            ->map(function (array $root): array {
                $children = collect($root['children'])->values();

                if ($root['direct'] > 0 && $children->isNotEmpty()) {
                    $children->push([
                        'category_id' => $root['category_id'],
                        'category' => $this->renamedCopy($root['category'], __('Direct')),
                        'amount' => $root['direct'],
                    ]);
                }

                return [
                    'category_id' => $root['category_id'],
                    'category' => $root['category'],
                    'amount' => $root['amount'],
                    'children' => $children->sortByDesc('amount')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Where the breakdown's own rows start inside a category chain: the top for
     * an undrilled breakdown, the position right after the drilled parent
     * otherwise. Null when the chain never passes through that parent.
     *
     * @param  array<int, string>  $chain
     */
    private function chainOffsetAfter(array $chain, ?string $drillParentId): ?int
    {
        if ($drillParentId === null) {
            return 0;
        }

        $index = array_search($drillParentId, $chain, true);

        return $index === false ? null : $index + 1;
    }

    /**
     * A detached copy of a category under a different name, used for the
     * synthetic "Direct" nodes that carry a parent's own spending.
     */
    private function renamedCopy(Category $category, string $name): Category
    {
        return (new Category)->forceFill([
            'id' => $category->id,
            'name' => $name,
            'icon' => $category->icon,
            'color' => $category->color,
            'type' => $category->type,
            'cashflow_direction' => $category->cashflow_direction,
            'parent_id' => $category->id,
        ]);
    }

    /**
     * The category ids from the top-level ancestor down to the category itself.
     *
     * @param  array<string, ?string>  $parentMap
     * @return array<int, string>
     */
    private function chainFromRoot(string $categoryId, array $parentMap): array
    {
        $chain = [];
        $current = $categoryId;
        $guard = 0;

        while ($current !== null && $guard++ < Category::MAX_DEPTH + 1) {
            array_unshift($chain, $current);
            $current = $parentMap[$current] ?? null;
        }

        return $chain;
    }

    /**
     * Resolve which node a category's amount should be attributed to.
     *
     * @param  array<string, ?string>  $parentMap
     * @return array{id: string, is_direct: bool}|null
     */
    private function displayNodeFor(string $categoryId, array $parentMap, ?string $drillParentId): ?array
    {
        $chain = $this->chainFromRoot($categoryId, $parentMap);
        $offset = $this->chainOffsetAfter($chain, $drillParentId);

        if ($offset === null) {
            return null;
        }

        // Nothing left in the chain means the spend sits on the drilled parent.
        return isset($chain[$offset])
            ? ['id' => $chain[$offset], 'is_direct' => false]
            : ['id' => $drillParentId, 'is_direct' => true];
    }

    /**
     * Whether assigning $parentId as the parent of $category would create a
     * cycle (the parent is the category itself or one of its descendants).
     */
    public function wouldCreateCycle(Category $category, string $parentId): bool
    {
        if ($parentId === $category->id) {
            return true;
        }

        return in_array($parentId, $this->descendantIds($category), true);
    }

    /**
     * Cascade a category's type and cashflow direction down to every
     * descendant, keeping the whole subtree on a single type.
     */
    public function syncDescendantTypes(Category $category): void
    {
        $descendantIds = $this->descendantIds($category);

        if ($descendantIds === []) {
            return;
        }

        Category::query()
            ->where('user_id', $category->user_id)
            ->whereIn('id', $descendantIds)
            ->update([
                'type' => $category->type,
                'cashflow_direction' => $category->cashflow_direction,
            ]);
    }

    /**
     * Move the category's direct children to a new parent, then delete it on
     * its own. The children are out of the way first, so the subtree the delete
     * sees is the category alone.
     *
     *
     * @return array{categories: int, transactions: int}
     *
     * @throws UniqueConstraintViolationException when a moved child's name collides at the destination
     */
    public function detachChildrenAndDelete(Category $category, ?string $newParentId): array
    {
        $category->children()->update(['parent_id' => $newParentId]);

        return $this->deleteSubtree($category);
    }

    /**
     * Soft-delete a category together with its whole subtree, uncategorizing
     * any transactions that pointed at the removed categories.
     *
     * Returns what the cascade cost, so a caller can report it back rather than
     * leave the user to discover the damage.
     *
     * @return array{categories: int, transactions: int}
     */
    public function deleteSubtree(Category $category): array
    {
        $ids = [$category->id, ...$this->descendantIds($category)];

        $referencedSplit = TransactionSplit::query()
            ->whereIn('category_id', $ids)
            ->orderBy('transaction_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($referencedSplit !== null) {
            throw ValidationException::withMessages([
                'category' => 'Categories used by split transactions cannot be deleted.',
            ]);
        }

        $uncategorized = Transaction::query()
            ->where('user_id', $category->user_id)
            ->whereIn('category_id', $ids)
            ->update(['category_id' => null]);

        $deleted = Category::query()
            ->where('user_id', $category->user_id)
            ->whereIn('id', $ids)
            ->delete();

        return ['categories' => $deleted, 'transactions' => $uncategorized];
    }
}
