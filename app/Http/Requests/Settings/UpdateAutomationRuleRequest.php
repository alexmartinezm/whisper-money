<?php

namespace App\Http\Requests\Settings;

use Illuminate\Support\Arr;

class UpdateAutomationRuleRequest extends StoreAutomationRuleRequest
{
    /**
     * The web form has no note field, so a save leaves the note alone: one set
     * through the API or MCP stays, even from a tab still sending an empty one.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        return Arr::except(parent::ruleAttributes(), ['action_note', 'action_note_iv']);
    }
}
