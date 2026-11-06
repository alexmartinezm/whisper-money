<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountWriteRules;

/**
 * What the account create and update requests share: the AccountWriteRules
 * the MCP tools validate with too, and the real estate, loan and credit card
 * detail fields, so an account of any type can be described in one request.
 */
trait ValidatesAccountWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    protected function accountRules(): AccountWriteRules
    {
        /** @var User $user */
        $user = $this->user();

        return new AccountWriteRules($user);
    }

    /**
     * The validated fields, with the linked accounts as stored.
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        $validated = $this->validated();

        foreach (['linked_loan_account_id', 'linked_real_estate_account_id'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = $this->idInSpace(Account::class, $field, $validated[$field]);
            }
        }

        return $validated;
    }

    /**
     * The type the request asks for, null when it sends none or an invalid one.
     */
    protected function requestedType(): ?AccountType
    {
        $type = $this->input('type');

        return is_string($type) ? AccountType::tryFrom($type) : null;
    }

    /**
     * The detail fields. One that does not belong to the account's type is
     * ignored. A linked account must be in the request's space: the shared
     * rules only hold it to the user, as the app does.
     *
     * @param  array<string, array<mixed>>  $rules  the AccountWriteRules for the account's type
     * @return array<string, array<mixed>>
     */
    protected function detailFields(array $rules): array
    {
        $fields = [
            'property_type', 'address', 'purchase_price', 'purchase_date', 'area_value', 'area_unit',
            'revaluation_percentage', 'notes', 'annual_interest_rate', 'loan_term_months',
            'original_amount', 'loan_start_date', 'statement_closing_date', 'payment_due_date',
            'statement_frequency', 'credit_limit',
        ];

        return [
            ...collect($fields)->mapWithKeys(fn (string $field): array => [$field => $rules[$field] ?? ['exclude']])->all(),
            'linked_loan_account_id' => $this->linkedAccountField($rules, 'linked_loan_account_id'),
            'linked_real_estate_account_id' => $this->linkedAccountField($rules, 'linked_real_estate_account_id'),
        ];
    }

    /**
     * @param  array<string, array<mixed>>  $rules
     * @return array<mixed>
     */
    private function linkedAccountField(array $rules, string $field): array
    {
        return isset($rules[$field]) ? [...$rules[$field], $this->inSpace(Account::class, 'an account')] : ['exclude'];
    }
}
