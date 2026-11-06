<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAutomationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteAutomationRuleRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteAutomationRule;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
