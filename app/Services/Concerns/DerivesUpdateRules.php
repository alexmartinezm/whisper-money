<?php

namespace App\Services\Concerns;

/**
 * For the shared writers whose update takes the fields of their create, any of
 * them left out: only what is sent changes.
 */
trait DerivesUpdateRules
{
    /**
     * @param  array<string, array<mixed>>  $createRules
     * @return array<string, array<mixed>>
     */
    private static function everyFieldOptional(array $createRules): array
    {
        return array_map(
            fn (array $rules): array => ['sometimes', ...array_filter($rules, fn (mixed $rule): bool => ! in_array($rule, ['required', 'sometimes'], true))],
            $createRules,
        );
    }
}
