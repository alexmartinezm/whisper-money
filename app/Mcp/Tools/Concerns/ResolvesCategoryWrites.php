<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Category;
use App\Models\Space;
use App\Services\Categories\CategoryWriter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * The parent lookup and the name check shared by the category write tools,
 * both by space, as every MCP lookup is. Where the category may then sit in the
 * tree, and the type and cashflow direction it derives from its parent, is
 * CategoryWriter's to decide.
 */
trait ResolvesCategoryWrites
{
    /**
     * The requested parent category in the space, or null when the request
     * makes (or keeps) a root or names no parent.
     */
    protected function parentCategory(Request $request, Space $space): ?Category
    {
        if (! $request->filled('parent_id')) {
            return null;
        }

        $parentId = $request->string('parent_id')->toString();

        $parent = Category::query()->forSpace($space)->whereKey($parentId)->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => "No category with id {$parentId} in space {$space->id}. Call list_categories to see valid ids.",
            ]);
        }

        return $parent;
    }

    /**
     * Refuse a name a sibling already has, whichever member's it is, optionally
     * ignoring one category by id (for edits).
     */
    protected function refuseTakenName(Space $space, string $name, ?string $parentId, ?string $ignoreId = null): void
    {
        if (app(CategoryWriter::class)->nameTaken($space, $name, $parentId, $ignoreId)) {
            throw ValidationException::withMessages(['name' => CategoryWriter::NameTakenMessage]);
        }
    }
}
