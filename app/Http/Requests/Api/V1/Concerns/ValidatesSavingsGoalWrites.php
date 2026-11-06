<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\MonthlyTargetType;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalIncomeWithholding;
use App\Services\SpaceResolver;
use Illuminate\Contracts\Validation\Validator;

/**
 * What the savings goal create and update requests share: the
 * SavingsGoalWriteRules rules and messages the MCP tools validate with too.
 * The fields of the other kind of goal are refused with a message naming the
 * kind they belong to, and so is an income rate where the income is not only
 * the caller's: a goal there could not show its figures through this API
 * (SavingsGoalIncomeWithholding).
 */
trait ValidatesSavingsGoalWrites
{
    use AddsRefusals;

    /**
     * @var array{0: array<string, array<mixed>>, 1: array<string, string>}|null
     */
    private ?array $writeRules = null;

    /**
     * The SavingsGoalWriteRules rules and messages for this write.
     *
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>}
     */
    abstract protected function savingsGoalWriteRules(User $user): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->writeRules()[1];
    }

    /**
     * Refuse unknown fields, and an income rate in a space with other members.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...$this->incomeRateRefusal($validator),
        ])];
    }

    /**
     * @return array<string, string>
     */
    private function incomeRateRefusal(Validator $validator): array
    {
        if ($validator->errors()->has('monthly_target_type')
            || $this->input('monthly_target_type') !== MonthlyTargetType::IncomeRate->value) {
            return [];
        }

        /** @var User $user */
        $user = $this->user();

        return app(SavingsGoalIncomeWithholding::class)->hasOtherMembers(app(SpaceResolver::class)->forApiRequest($this), $user->id)
            ? ['monthly_target_type' => 'An income rate is measured against the income of everyone in a shared space. Use an amount in this space.']
            : [];
    }

    /**
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>}
     */
    protected function writeRules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return $this->writeRules ??= $this->savingsGoalWriteRules($user);
    }
}
