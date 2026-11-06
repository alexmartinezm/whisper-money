<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAutomationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApplyAutomationRuleRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteAutomationRule;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'only_uncategorized' => ['sometimes', 'boolean'],
        ];
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run', true);
    }

    public function onlyUncategorized(): bool
    {
        return $this->boolean('only_uncategorized', true);
    }
}
