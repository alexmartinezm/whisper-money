<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SavingsGoalKind;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ValidatesSavingsGoalWrites;
use App\Models\Account;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalWriteRules;
use App\Services\SpaceResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsGoalRequest extends FormRequest
{
    use ChecksIdsInSpace, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->writeRules()[0],
            ...($this->kind() === SavingsGoalKind::Monthly ? [
                'auto_tag_account_id' => ['nullable', 'bail', 'string', $this->inSpace(Account::class, 'an account'), $this->feedableAccount(...)],
            ] : []),
        ];
    }

    private function kind(): ?SavingsGoalKind
    {
        $kind = $this->input('kind');

        return is_string($kind) ? SavingsGoalKind::tryFrom($kind) : null;
    }

    protected function savingsGoalWriteRules(User $user): array
    {
        return app(SavingsGoalWriteRules::class)->forCreate($user, $this->kind());
    }

    private function feedableAccount(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var User $user */
        $user = $this->user();

        $account = app(SpaceResolver::class)->ownedForApiRequest($this, Account::query())->findOrFail((string) $value);

        // A rule reads the name in the clear, so on ciphertext it would never
        // match, and GET /api/v1/automation-rules would show it.
        if ($account->name_iv !== null) {
            $fail("Account {$account->id} has its name encrypted on your device, so a rule cannot match it. Pick another savings account, or leave auto_tag_account_id out and tag contributions to the goal with its label.");

            return;
        }

        $refusal = app(SavingsGoalWriteRules::class)->autoTagAccountRefusal(
            $account,
            'Pick an account of type savings from GET /api/v1/accounts.',
            'tag contributions to the goal with its label.',
            $user,
        );

        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
