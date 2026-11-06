<?php

namespace App\Rules;

use App\Services\AutomationRules\RuleConditions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An automation rule's conditions, as RuleConditions accepts them.
 *
 * Implicit, so it also runs on a blank string: Laravel skips any other rule for
 * one, and the request restores the conditions as sent, so `""` would reach the
 * column and break every page that reads the rule.
 */
class ValidRuleConditions implements ValidationRule
{
    /** @api read by the validator */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = RuleConditions::error($value, $attribute);

        if ($error !== null) {
            $fail($error);
        }
    }
}
