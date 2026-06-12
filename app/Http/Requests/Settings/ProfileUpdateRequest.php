<?php

namespace App\Http\Requests\Settings;

use App\Features\CustomMonthStartDay;
use App\Models\User;
use App\Services\CurrencyOptions;
use App\Services\UserMonthPeriodService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currencyOptions = app(CurrencyOptions::class);

        $rules = [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'currency_code' => ['required', 'string', 'max:3', Rule::in($currencyOptions->primaryCodes())],
            'locale' => ['nullable', 'string', Rule::in(['en', 'es'])],
        ];

        if (Feature::for($this->user())->active(CustomMonthStartDay::class)) {
            $rules['month_start_day'] = ['sometimes', 'required', 'integer', Rule::in(UserMonthPeriodService::ALLOWED_START_DAYS)];
        }

        return $rules;
    }
}
