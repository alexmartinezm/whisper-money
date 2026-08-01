<?php

namespace App\Http\Requests;

use App\Enums\RecurringSeriesUserState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the two fields a user owns are writable. Cadence, amount and dates
     * are derived from the ledger and are rewritten on the next detection run,
     * so accepting them here would silently discard the edit.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'user_state' => ['sometimes', Rule::enum(RecurringSeriesUserState::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.max' => 'The name may not be longer than 255 characters.',
            'user_state' => 'The state must be one of: detected, confirmed, ignored.',
        ];
    }
}
