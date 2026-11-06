<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\User;

/**
 * What the savings goal create and update requests share: the
 * SavingsGoalWriteRules rules and messages the MCP tools validate with too.
 * The fields of the other kind of goal are refused with a message naming the
 * kind they belong to.
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
     * @return array{0: array<string, array<mixed>>, 1: array<string, string>}
     */
    protected function writeRules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return $this->writeRules ??= $this->savingsGoalWriteRules($user);
    }
}
