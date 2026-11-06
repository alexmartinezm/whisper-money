<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Budget;
use Illuminate\Validation\ValidationException;

/**
 * For the `/api/v1/budgets/{budget}` write requests: see
 * ResolvesOwnedRouteModel. An archived budget is read-only for good
 * (BudgetPolicy), which is a 422 rather than a refusal, but it can still be
 * deleted.
 */
trait ResolvesRouteBudget
{
    use ResolvesOwnedRouteModel;

    public function budget(): Budget
    {
        /** @var Budget */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [Budget::class, 'budget'];
    }

    protected function failedAuthorization(): never
    {
        throw ValidationException::withMessages([
            'budget' => "Budget {$this->budget()->id} is archived and can no longer be changed. Archiving cannot be undone, but you can still delete it.",
        ]);
    }
}
