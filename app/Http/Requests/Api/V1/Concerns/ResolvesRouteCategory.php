<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;

/**
 * For the `/api/v1/categories/{category}` write requests: see
 * ResolvesOwnedRouteModel.
 */
trait ResolvesRouteCategory
{
    use ResolvesOwnedRouteModel;

    public function category(): Category
    {
        /** @var Category */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [Category::class, 'category'];
    }
}
