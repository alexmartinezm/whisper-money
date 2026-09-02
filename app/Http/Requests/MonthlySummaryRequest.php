<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class MonthlySummaryRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // The month reported on, as YYYY-MM. Only months that have already
            // finished can be analysed: a month still running has no figures
            // worth freezing, and freezing it would fix a half-month in place.
            'period' => ['required', 'date_format:Y-m', 'before:'.now()->startOfMonth()->format('Y-m')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period.before' => __('A month can only be analysed once it has ended.'),
        ];
    }

    /**
     * First day of the requested month.
     */
    public function month(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('period').'-01')->startOfMonth();
    }
}
