<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\Requests\Api\V1\StoreSavingsGoalRequest;
use App\Http\Requests\Api\V1\UpdateSavingsGoalRequest;
use App\Http\Resources\V1\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavingsGoalController extends Controller
{
    public function __construct(private SavingsGoalService $goals) {}

    /**
     * List savings goals
     *
     * Your goals in a space, newest first, archived ones included. As in the
     * app, another member's goals are not listed.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            $this->owned($request, SavingsGoal::query())->with(['user:id,currency_code', 'label' => fn ($query) => $query->withTrashed()]),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            SavingsGoalResource::class,
        );
    }

    /**
     * Retrieve a savings goal
     *
     * @param  string  $savings_goal  The goal's id.
     */
    public function show(Request $request, string $savings_goal): SavingsGoalResource
    {
        return new SavingsGoalResource($this->inSpace($request, SavingsGoal::class, $savings_goal));
    }

    /**
     * Create a savings goal
     *
     * Creates the goal in the space, with a label of the same name to tag its
     * contributions with. A one-off goal needs `target_amount`; a monthly goal
     * needs `monthly_target_type` with its `monthly_target_amount` or
     * `monthly_target_rate`, and its first month opens right away. The fields
     * of the other kind are refused. A monthly goal given
     * `auto_tag_account_id`, one of your savings accounts in the space, also
     * gets an automation rule that tags the transfers into that account to the
     * goal, the ones already there since the start of this month included.
     */
    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $goal = $this->goals->createFromInput($this->user($request), $request->validated(), $this->space($request));

        return (new SavingsGoalResource($goal->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update a savings goal
     *
     * Changes only the fields you send, and only the fields of the goal's
     * kind: the others, and `kind` itself, are refused. A new name renames the
     * goal's label too. A new monthly target applies to the month in progress
     * and later ones, never to the months already over. An archived goal can
     * no longer change: `422` under `savings_goal`.
     *
     * @param  string  $savings_goal  The goal's id.
     */
    public function update(UpdateSavingsGoalRequest $request, string $savings_goal): SavingsGoalResource
    {
        return new SavingsGoalResource($this->goals->update($request->savingsGoal(), $request->validated())->refresh());
    }
}
