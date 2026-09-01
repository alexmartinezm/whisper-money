<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryDeletionStrategy;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a category, uncategorizing its transactions. The strategy decides what happens to its child categories: "reparent" (default) lifts them to the deleted category\'s parent, "promote" turns them into roots, "cascade" deletes the whole subtree and uncategorizes affected transactions. Because "cascade" can remove categories the user never named, it also requires confirm_cascade: true; without it nothing is deleted. The response reports how many categories went and how many transactions were left uncategorized.')]
class DeleteCategory extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->string()->description('Id of the category to delete.')->required(),
            'strategy' => $schema->string()->enum(array_column(CategoryDeletionStrategy::cases(), 'value'))->description('How to handle child categories. Defaults to "reparent".'),
            'confirm_cascade' => $schema->boolean()->description('Required, and must be true, when strategy is "cascade". Acknowledges that the whole subtree goes and its transactions lose their category.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        return DB::transaction(function () use ($request, $user): Response {
            $request->validate([
                'strategy' => ['sometimes', Rule::enum(CategoryDeletionStrategy::class)],
            ]);

            $strategy = $request->enum('strategy', CategoryDeletionStrategy::class) ?? CategoryDeletionStrategy::Reparent;

            // Refused before anything is resolved or locked, so an unconfirmed
            // cascade cannot leave a trace of having been attempted.
            if ($strategy === CategoryDeletionStrategy::Cascade && ! $request->boolean('confirm_cascade')) {
                return Response::error('Deleting with strategy "cascade" removes the category, every category below it, and the category of every transaction in that subtree. Call list_categories to see what would go, then pass confirm_cascade: true to proceed. Nothing has been deleted.');
            }

            $space = $this->resolveSpace($request, $user);
            $category = $this->categoryInSpace($request, $space);
            $tree = new CategoryTree;
            $category = $tree->lockSubtreeForMutation($category);

            $affected = match ($strategy) {
                CategoryDeletionStrategy::Cascade => $tree->deleteSubtree($category),
                CategoryDeletionStrategy::Promote => $this->detachChildrenAndDelete($tree, $category, null),
                CategoryDeletionStrategy::Reparent => $this->detachChildrenAndDelete($tree, $category, $category->parent_id),
            };

            return $this->json([
                'deleted' => true,
                'id' => $category->id,
                'strategy' => $strategy->value,
                'categories_deleted' => $affected['categories'],
                'transactions_uncategorized' => $affected['transactions'],
            ]);
        }, attempts: 5);
    }

    /**
     * Move the category's direct children to a new parent, then delete it.
     *
     * The delete goes through the tree so the category's own transactions are
     * uncategorized, exactly as the cascade does: a transaction left pointing at
     * a soft-deleted category is counted by the dashboard widget and dropped by
     * the cashflow screen, and the same month then reads two different ways.
     *
     * @return array{categories: int, transactions: int}
     */
    private function detachChildrenAndDelete(CategoryTree $tree, Category $category, ?string $newParentId): array
    {
        try {
            return $tree->detachChildrenAndDelete($category, $newParentId);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'strategy' => 'A category with the same name already exists at the destination level. Rename it first.',
            ]);
        }
    }
}
