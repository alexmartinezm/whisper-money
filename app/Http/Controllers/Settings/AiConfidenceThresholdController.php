<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAiConfidenceThresholdRequest;
use Illuminate\Http\RedirectResponse;

class AiConfidenceThresholdController extends Controller
{
    public function update(UpdateAiConfidenceThresholdRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['ai_confidence_threshold' => $request->integer('ai_confidence_threshold')]
        );

        return back();
    }
}
