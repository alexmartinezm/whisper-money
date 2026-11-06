<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The date range every analytics endpoint reports on. The previous period the
 * figures are compared with is the same number of days right before it, or the
 * same number of whole months when the range is whole months.
 *
 * The range caps are this API's alone: the screens and the MCP tools go by
 * PeriodComparator::rules().
 */
class AnalyticsRangeRequest extends FormRequest
{
    /**
     * The most calendar months a range may touch: every one of them is walked
     * through, so an unbounded range would be an unbounded amount of work.
     */
    protected const MAX_MONTHS = 120;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = PeriodComparator::rules();

        return [
            'from' => [...$rules['from'], 'date_format:Y-m-d'],
            'to' => [...$rules['to'], 'date_format:Y-m-d', Rule::when(is_string($this->input('from')), 'after_or_equal:from')],
        ];
    }

    /**
     * Refuse a key the endpoint does not take, as the lists do, `space`
     * included: these figures add up every space, and a caller who sent one
     * must not read the total as that space's. Then refuse a range longer than
     * the endpoint reports on, under `to`.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_map(strval(...), array_keys($this->all())), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, "This endpoint does not take a {$key} parameter.");
            }

            if ($validator->errors()->isEmpty() && ($refusal = $this->rangeRefusal($this->period())) !== null) {
                $validator->errors()->add('to', $refusal);
            }
        }];
    }

    public function period(): PeriodComparator
    {
        return new PeriodComparator(Carbon::parse($this->input('from')), Carbon::parse($this->input('to')));
    }

    /**
     * Why the range is too long, or null when it is not.
     */
    protected function rangeRefusal(PeriodComparator $period): ?string
    {
        $months = ($period->to->year - $period->from->year) * 12 + $period->to->month - $period->from->month + 1;

        return $months > static::MAX_MONTHS
            ? 'The range can touch at most '.static::MAX_MONTHS.' calendar months.'
            : null;
    }
}
