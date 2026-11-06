<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Label;

/**
 * For the `/api/v1/labels/{label}` write requests: see
 * ResolvesOwnedRouteModel.
 */
trait ResolvesRouteLabel
{
    use ResolvesOwnedRouteModel;

    public function label(): Label
    {
        /** @var Label */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [Label::class, 'label'];
    }
}
