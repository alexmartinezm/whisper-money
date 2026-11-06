<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CashflowRequest;
use App\Http\Requests\Api\V1\NetWorthRequest;
use App\Http\Requests\Api\V1\SpendingByCategoryRequest;
use App\Http\Resources\V1\CashflowResource;
use App\Http\Resources\V1\NetWorthResource;
use App\Http\Resources\V1\SpendingByCategoryResource;
use App\Services\CashflowAnalyticsService;
use App\Services\CategorySpendingService;
use App\Services\NetWorthAnalyticsService;

/**
 * Figures rolled up from the caller's own transactions and balances over a
 * date range. They add up every space the caller has, as the app's screens and
 * the MCP tools do, and only ever the caller's own rows: another member of a
 * shared space's money never counts. There is no `space`: a figure for one
 * space is not what the screens show, so sending one is refused rather than
 * ignored.
 */
class AnalyticsController extends Controller
{
    /**
     * Retrieve the cashflow
     *
     * The cashflow of a date range: income, expenses, savings and investments
     * against the previous period, your expenses split by the budgets that
     * track them (fixed, variable and general), what your budgets still
     * reserve, where the money came from and went by category, and the monthly
     * trend. The range can touch at most 24 calendar months.
     */
    public function cashflow(CashflowRequest $request, CashflowAnalyticsService $cashflow): CashflowResource
    {
        $period = $request->period();

        return new CashflowResource($cashflow->forRange($this->user($request), $period->from, $period->to));
    }

    /**
     * Retrieve the net worth
     *
     * Your net worth at the end of a date range against the end of the
     * previous period, and every account's balance over the range, monthly or
     * daily. The accounts you switched off in net worth are left out. A
     * monthly range can touch at most 120 calendar months, a daily one cover
     * at most 366 days.
     */
    public function netWorth(NetWorthRequest $request, NetWorthAnalyticsService $netWorth): NetWorthResource
    {
        $user = $this->user($request);

        return new NetWorthResource([
            ...$netWorth->forRange($user, $request->period(), $request->daily()),
            'counted_account_ids' => $netWorth->countedAccountIds($user),
        ]);
    }

    /**
     * Retrieve spending by category
     *
     * Your expenses in a date range by top-level category, each with its
     * subcategories rolled into it. Pass `parent_category_id` to drill into a
     * category: its children come back instead, plus a row for what was booked
     * to the category itself (`is_direct`). The range can touch at most 120
     * calendar months.
     */
    public function spendingByCategory(SpendingByCategoryRequest $request, CategorySpendingService $spending): SpendingByCategoryResource
    {
        $period = $request->period();

        return new SpendingByCategoryResource(
            $spending->forPeriod($this->user($request)->id, $period->from, $period->to, $request->parentCategoryId()),
        );
    }
}
