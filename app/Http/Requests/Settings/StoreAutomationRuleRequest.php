<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\AutomationRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAutomationRuleRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.AutomationRule::MAX_TITLE_LENGTH],
            'priority' => ['required', 'integer', 'min:0'],
            'rules_json' => ['bail', 'required', 'json', function ($attribute, $value, $fail) {
                $decoded = json_decode($value, true);
                if (! is_array($decoded) || empty($decoded)) {
                    $fail('The rules JSON must be a valid JsonLogic object.');
                }
            }],
            'action_category_id' => [
                'nullable',
                'string',
                $this->userOwned('categories'),
            ],
            'action_note' => ['nullable', 'string'],
            'action_note_iv' => ['nullable', 'string', 'required_with:action_note'],
            'action_label_ids' => ['nullable', 'array'],
            'action_label_ids.*' => [
                'required',
                'string',
                'uuid',
                $this->userOwned('labels'),
            ],
        ];
    }

    /**
     * The validated fields as the model takes them, without the labels. The
     * form posts the conditions as a JSON string: left as one, the model's
     * array cast would encode it a second time.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        return [
            ...$this->safe()->except('action_label_ids'),
            'rules_json' => json_decode($this->validated('rules_json'), true),
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasLabels = ! empty($this->action_label_ids);
            if (! $this->action_category_id && ! $hasLabels) {
                $validator->errors()->add('action_category_id', 'At least one action (category or labels) must be provided.');
            }
        });
    }
}
