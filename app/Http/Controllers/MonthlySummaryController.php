<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonthlySummaryRequest;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\Summaries;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The month in review, on demand.
 *
 * Nothing is generated on a page view: the reader asks for a month, it is frozen
 * once, and every later visit reads those same figures back. The AI paragraphs
 * are a second, separate ask for the same reason — they cost money, so they are
 * never a side effect of opening a page.
 */
class MonthlySummaryController extends Controller
{
    public function index(Request $request, Summaries $summaries, AnalysisWriter $analysis): Response
    {
        $user = $request->user();
        $month = $this->requestedMonth($request);
        $summary = $summaries->find($user, $month);

        return Inertia::render('monthly-summary/index', [
            'period' => $month->format('Y-m'),
            'summary' => $summary === null ? null : [
                'id' => $summary->id,
                'period' => $summary->period,
                'payload' => $summary->payload,
                'complete' => $summary->complete,
                'analysis' => $summary->ai_analysis,
                'generated_at' => $summary->created_at->toIso8601String(),
            ],
            // Whether asking for the written analysis is worth offering at all.
            'canAnalyse' => $analysis->eligible($user),
        ]);
    }

    /**
     * Freeze the month. Idempotent: a month already closed comes back untouched.
     */
    public function store(MonthlySummaryRequest $request, Summaries $summaries): RedirectResponse
    {
        $summary = $summaries->freeze($request->user(), $request->month());

        if ($summary === null) {
            return back()->with('error', __('There is nothing recorded in that month yet.'));
        }

        return to_route('monthly-summary.index', ['period' => $summary->period]);
    }

    /**
     * Write the analysis for an already-frozen month, once.
     */
    public function analyse(
        Request $request,
        MonthlySummary $monthlySummary,
        AnalysisWriter $analysis,
    ): RedirectResponse {
        abort_unless($monthlySummary->user_id === $request->user()->id, 403);

        if ($analysis->write($monthlySummary, $request->user()) === null) {
            return back()->with('error', __('The analysis could not be written right now. Try again in a moment.'));
        }

        return back();
    }

    /**
     * The month being looked at: the one asked for, or the last one that ended.
     */
    private function requestedMonth(Request $request): Carbon
    {
        $period = $request->query('period');

        if (is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
            return Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        }

        return now()->subMonth()->startOfMonth();
    }
}
