<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Models\Label;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceTransactionLabelsRequest extends FormRequest
{
    use AddsRefusals, ChecksIdsInSpace, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->idListInSpace('label_ids', 'present', 100, Label::class, 'a label');
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->idListAsStored(Label::class, 'label_ids');
    }
}
