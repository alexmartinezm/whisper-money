<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteSavingsGoal;
use App\Http\Requests\Api\V1\Concerns\ValidatesSavingsGoalWrites;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalWriteRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavingsGoalRequest extends FormRequest
{
    use ResolvesRouteSavingsGoal, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->writeRules()[0];
    }

    protected function savingsGoalWriteRules(User $user): array
    {
        return app(SavingsGoalWriteRules::class)->forUpdate($user, $this->savingsGoal());
    }
}
