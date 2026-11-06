<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class SplitTransactionRequest extends FormRequest
{
    use AddsRefusals, ChecksIdsInSpace, ResolvesRouteTransaction;

    private const int MaxLines = 20;

    /**
     * The shape of the lines. What keeps a split honest (they add up, they
     * share a sign, their categories agree) is ReplaceTransactionSplits', the
     * check every path goes through, not repeated here. Oversized lists get no
     * rules for their lines: see idListInSpace().
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $lines = $this->input('splits');

        return [
            'splits' => ['required', 'array', 'list', 'min:2', 'max:'.self::MaxLines],
            ...is_array($lines) && count($lines) > self::MaxLines ? [] : [
                'splits.*' => ['array:category_id,amount'],
                'splits.*.category_id' => ['required', 'string', $this->inSpace(Category::class, 'a category')],
                'splits.*.amount' => ['required', 'integer', 'not_in:0'],
            ],
        ];
    }

    /**
     * The lines as ReplaceTransactionSplits takes them, the categories as
     * stored: it compares them against what it finds.
     *
     * @return array<int, array{category_id: string, amount: int}>
     */
    public function lines(): array
    {
        return array_map(fn (array $line): array => [
            'category_id' => $this->idInSpace(Category::class, 'splits.*.category_id', $line['category_id']),
            'amount' => (int) $line['amount'],
        ], $this->validated('splits'));
    }
}
