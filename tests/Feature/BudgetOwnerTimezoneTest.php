<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateBudgetPeriod;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetManagementService;
use App\Services\BudgetPeriodService;
use App\Services\Budgets\BudgetAssignmentLookups;
use App\Services\Budgets\ProjectBudgetPace;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Mcp\Server\Testing\PendingTestResponse;

use function Pest\Laravel\withHeaders;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the period in progress is the owner's, not the server's.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A monthly carry-over budget with October and November 2026 periods.
 */
function octoberAndNovemberBudget(?string $timezone): Budget
{
    $user = User::factory()->create(['onboarded_at' => now(), 'timezone' => $timezone]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    foreach ([['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30']] as [$start, $end]) {
        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $end,
            'allocated_amount' => 10_000,
            'carried_over_amount' => 0,
        ]);
    }

    return $budget;
}

function mcpWriter(User $user): PendingTestResponse
{
    $user->withAccessToken($user->createToken('mcp-timezone-test', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user);
}

describe('User::localToday', function () {
    it('reads the date on the user\'s wall clock', function (?string $timezone, string $now, string $expected) {
        Carbon::setTestNow(Carbon::parse($now));

        $today = User::factory()->make(['timezone' => $timezone])->localToday();

        expect($today->toDateTimeString())->toBe("{$expected} 00:00:00")
            ->and($today->timezoneName)->toBe('UTC');
    })->with([
        'west of UTC, still yesterday' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10-31'],
        'UTC' => ['UTC', '2026-11-01 00:30:00', '2026-11-01'],
        'east of UTC, already tomorrow' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11-01'],
        'no timezone falls back to the app' => [null, '2026-11-01 00:30:00', '2026-11-01'],
        'an invalid timezone falls back to the app' => ['Mars/Olympus_Mons', '2026-11-01 00:30:00', '2026-11-01'],
    ]);

    it('only reports a timezone it can parse', function () {
        expect(User::factory()->make(['timezone' => 'Asia/Tokyo'])->validTimezone())->toBe('Asia/Tokyo')
            ->and(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])->validTimezone())->toBeNull()
            ->and(User::factory()->make(['timezone' => null])->validTimezone())->toBeNull()
            ->and(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])->localTimezone())->toBe('UTC');
    });
});

describe('the current period', function () {
    it('is the one covering the owner\'s today', function (?string $timezone, string $now, string $expectedStart) {
        Carbon::setTestNow(Carbon::parse($now));

        $budget = octoberAndNovemberBudget($timezone);

        expect($budget->getCurrentPeriod()->start_date->toDateString())->toBe($expectedStart)
            ->and(Budget::query()->with('currentPeriods')->find($budget->id)->currentPeriods->first()->start_date->toDateString())->toBe($expectedStart);
    })->with([
        'Buenos Aires at 2026-11-01 00:30 UTC' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10-01'],
        'UTC at 2026-11-01 00:30 UTC' => ['UTC', '2026-11-01 00:30:00', '2026-11-01'],
        'no timezone at 2026-11-01 00:30 UTC' => [null, '2026-11-01 00:30:00', '2026-11-01'],
        'Tokyo at 2026-10-31 16:00 UTC' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11-01'],
        'UTC at 2026-10-31 16:00 UTC' => ['UTC', '2026-10-31 16:00:00', '2026-10-01'],
    ]);

    it('is eager loaded per owner when one list mixes timezones', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $buenosAires = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');
        $utc = octoberAndNovemberBudget('UTC');

        $budgets = Budget::query()->with('currentPeriods')->get()->keyBy('id');

        expect($budgets[$buenosAires->id]->currentPeriods->pluck('start_date')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($budgets[$utc->id]->currentPeriods->pluck('start_date')->map->toDateString()->all())->toBe(['2026-11-01']);
    });

    it('is the owner\'s on the budgets page', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $budget = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');

        $this->actingAs($budget->user)->get(route('budgets.index'))
            ->assertInertia(fn ($page) => $page->where('budgets.0.periods.0.start_date', '2026-10-01'));

        $this->actingAs($budget->user)->get(route('budgets.show', $budget))
            ->assertInertia(fn ($page) => $page
                ->where('currentPeriod.start_date', '2026-10-01')
                // November is still the one being planned, not the one in progress.
                ->where('nextPlanningPeriod.start_date', '2026-11-01')
                // Loaded to read the owner's today, never sent with the budget.
                ->missing('budget.user'));
    });

    it('is the owner\'s in the public API', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $budget = octoberAndNovemberBudget('America/Argentina/Buenos_Aires');

        withHeaders(apiV1Bearer($budget->user))->getJson('/api/v1/budgets')
            ->assertOk()
            ->assertJsonPath('data.0.current_period.start_date', '2026-10-01');

        withHeaders(apiV1Bearer($budget->user))->getJson("/api/v1/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('data.current_period.start_date', '2026-10-01');
    });

    it('starts a new budget on the owner\'s today', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $user = User::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => 1,
        ]);

        $period = app(BudgetPeriodService::class)->generatePeriod($budget);

        expect($period->start_date->toDateString())->toBe('2026-10-01');
    });
});

describe('budgets:generate-periods', function () {
    it('closes a period once it is over for the owner', function (?string $timezone, string $now, bool $octoberClosed) {
        Carbon::setTestNow(Carbon::parse($now));

        $budget = octoberAndNovemberBudget($timezone);

        $this->artisan('budgets:generate-periods')->assertSuccessful();

        $november = $budget->periods()->where('start_date', '2026-11-01')->first();

        // Nothing was spent in October, so closing it carries the whole 10,000 over.
        expect($november->carried_over_amount)->toBe($octoberClosed ? 10_000 : 0);
    })->with([
        'Buenos Aires at 2026-11-01 00:30 UTC: still October' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', false],
        'Buenos Aires at 2026-11-01 03:30 UTC: November' => ['America/Argentina/Buenos_Aires', '2026-11-01 03:30:00', true],
        'UTC at 2026-11-01 00:30 UTC' => ['UTC', '2026-11-01 00:30:00', true],
        'no timezone at 2026-11-01 00:30 UTC' => [null, '2026-11-01 00:30:00', true],
        'Tokyo at 2026-10-31 16:00 UTC: already November' => ['Asia/Tokyo', '2026-10-31 16:00:00', true],
        'UTC at 2026-10-31 16:00 UTC' => ['UTC', '2026-10-31 16:00:00', false],
    ]);

    it('keeps the chain ahead of the owner\'s today', function () {
        // Already 1 November in Tokyo: November is current, so December and
        // January are the two periods ahead.
        Carbon::setTestNow(Carbon::parse('2026-10-31 16:00:00'));

        $budget = octoberAndNovemberBudget('Asia/Tokyo');

        $this->artisan('budgets:generate-periods')->assertSuccessful();
        $this->artisan('budgets:generate-periods')->assertSuccessful();

        expect($budget->periods()->orderBy('start_date')->pluck('start_date')->map->toDateString()->all())
            ->toBe(['2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01']);
    });

    it('runs every hour, since periods end at each owner\'s midnight', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'budgets:generate-periods'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(60);
    });
});

it('refuses existence queries on currentPeriods, which cannot know each owner\'s today', function () {
    Budget::query()->whereHas('currentPeriods')->get();
})->throws(LogicException::class);

/*
 * The fork's own readers of "today": everything that hands an application date
 * to BudgetManagementService, the MCP tools, the pace warning, the catch-all
 * and the line a period freezes on. On both sides of UTC: 2026-11-01 00:30 UTC
 * is still 31 October in Los Angeles, 2026-10-31 12:00 UTC is already
 * 1 November in Auckland.
 */
describe('on both sides of UTC', function () {
    dataset('owners around the turn of the month', [
        'Los Angeles, still October' => ['America/Los_Angeles', '2026-11-01 00:30:00', '2026-10-31', '2026-10-01', '2026-11-01'],
        'Auckland, already November' => ['Pacific/Auckland', '2026-10-31 12:00:00', '2026-11-01', '2026-11-01', null],
        'Madrid, already November' => ['Europe/Madrid', '2026-10-31 23:30:00', '2026-11-01', '2026-11-01', null],
        'no timezone, the app\'s date' => [null, '2026-11-01 00:30:00', '2026-11-01', '2026-11-01', null],
        'an invalid timezone, the app\'s date' => ['Mars/Olympus_Mons', '2026-11-01 00:30:00', '2026-11-01', '2026-11-01', null],
    ]);

    it('lists the same period in progress on the web, the API and MCP', function (?string $timezone, string $now, string $today, string $currentStart, ?string $nextStart) {
        Carbon::setTestNow(Carbon::parse($now));
        $budget = octoberAndNovemberBudget($timezone);

        $this->actingAs($budget->user)->get(route('budgets.index'))
            ->assertInertia(fn ($page) => $page->where('budgets.0.periods.0.start_date', $currentStart));

        $this->actingAs($budget->user)->get(route('budgets.show', $budget))
            ->assertInertia(fn ($page) => $page->where('currentPeriod.start_date', $currentStart));

        withHeaders(apiV1Bearer($budget->user))->getJson('/api/v1/budgets')
            ->assertJsonPath('data.0.current_period.start_date', $currentStart);

        withHeaders(apiV1Bearer($budget->user))->getJson("/api/v1/budgets/{$budget->id}")
            ->assertJsonPath('data.current_period.start_date', $currentStart);

        $listed = mcpPayload(WhisperMoneyServer::actingAs($budget->user)->tool(ListBudgets::class)->assertOk());

        expect($listed['as_of'])->toBe($today)
            ->and($listed['budgets'][0]['current_period']['start_date'])->toBe($currentStart)
            ->and($listed['budgets'][0]['next_period']['start_date'] ?? null)->toBe($nextStart);
    })->with('owners around the turn of the month');

    it('freezes a period on the same line the command closes it on', function (?string $timezone, string $now, string $today, string $currentStart) {
        Carbon::setTestNow(Carbon::parse($now));
        $budget = octoberAndNovemberBudget($timezone);

        expect($budget->periods()->where('start_date', '2026-10-01')->sole()->hasEnded())->toBe($currentStart === '2026-11-01');
    })->with('owners around the turn of the month');

    it('reads a claim on a running budget on the owner\'s calendar', function (?string $timezone, string $now, string $today, string $currentStart) {
        Carbon::setTestNow(Carbon::parse($now));
        $budget = octoberAndNovemberBudget($timezone);
        $category = Category::factory()->create(['user_id' => $budget->user_id, 'space_id' => $budget->space_id]);
        $budget->categories()->attach($category);
        $catchAll = Budget::factory()->monthly()->create([
            'user_id' => $budget->user_id,
            'space_id' => $budget->space_id,
            'is_catch_all' => true,
        ]);
        $transaction = Transaction::factory()->create([
            'user_id' => $budget->user_id,
            'category_id' => $category->id,
            'transaction_date' => '2026-10-30',
            'amount' => -1000,
        ]);

        $claimants = app(BudgetAssignmentLookups::class)->runningClaimants($catchAll, $transaction);

        expect($claimants->modelKeys())->toBe($currentStart === '2026-10-01' ? [$budget->id] : []);
    })->with('owners around the turn of the month');

    it('warns about the pace of the period the owner is in', function (?string $timezone, string $now, string $today, string $currentStart) {
        Carbon::setTestNow(Carbon::parse($now));
        $budget = octoberAndNovemberBudget($timezone);
        // One long period, so both clocks read it: 9,000 spent of 10,000 over
        // 45 days, and the pace differs by the one elapsed day between them.
        BudgetTransaction::factory()->create([
            'budget_period_id' => $budget->periods()->where('start_date', '2026-10-01')->sole()->id,
            'amount' => 9_000,
        ]);
        BudgetPeriod::query()->where('budget_id', $budget->id)->where('start_date', '2026-10-01')->update(['end_date' => '2026-11-14']);
        BudgetPeriod::query()->where('budget_id', $budget->id)->where('start_date', '2026-11-01')->update(['start_date' => '2026-11-15', 'end_date' => '2026-12-14']);

        $pace = app(ProjectBudgetPace::class)->forUser($budget->user);

        $elapsed = $currentStart === '2026-10-01' ? 31 : 32;

        expect($pace[0]['projected'])->toBe((int) round(9_000 / $elapsed * 45));
    })->with('owners around the turn of the month');

    it('moves a limit from the owner\'s date on, on the web, the API and MCP', function (string $surface, string $timezone, string $now, int $expected) {
        // A limit reaches the period in progress only on its first day, and
        // that day is the one thing the two clocks disagree on here.
        Carbon::setTestNow(Carbon::parse($now));
        $budget = octoberAndNovemberBudget($timezone);
        $budget->periods()->where('start_date', '2026-10-01')->update(['end_date' => '2026-10-30']);
        $budget->periods()->where('start_date', '2026-11-01')->update(['start_date' => '2026-10-31', 'end_date' => '2026-11-29']);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        match ($surface) {
            'web' => $this->actingAs($budget->user)
                ->patch(route('budgets.update', $budget), ['allocated_amount' => 25_000])
                ->assertRedirect(),
            'api' => withHeaders(apiV1Bearer($budget->user, ['api:read', 'api:write']))
                ->patchJson("/api/v1/budgets/{$budget->id}", ['allocated_amount' => 25_000])
                ->assertOk(),
            'mcp' => mcpWriter($budget->user)
                ->tool(UpdateBudget::class, ['budget_id' => $budget->id, 'allocated_amount' => 25_000])
                ->assertOk(),
        };

        expect($budget->periods()->where('start_date', '>=', '2026-10-31')->orderBy('start_date')->pluck('allocated_amount')->first())
            ->toBe($expected)
            ->and($budget->periods()->where('start_date', '2026-10-01')->sole()->allocated_amount)->toBe(10_000);
    })->with(['web', 'api', 'mcp'])->with([
        'Los Angeles, the period\'s first day' => ['America/Los_Angeles', '2026-11-01 00:30:00', 25_000],
        'Auckland, a day past it' => ['Pacific/Auckland', '2026-10-31 12:00:00', 10_000],
    ]);

    it('refuses a one-off on the month that is over for the owner, on the API and MCP', function (string $surface) {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00'));
        $budget = octoberAndNovemberBudget('Pacific/Auckland');

        if ($surface === 'api') {
            withHeaders(apiV1Bearer($budget->user, ['api:read', 'api:write']))
                ->patchJson("/api/v1/budgets/{$budget->id}/period", ['date' => '2026-10-20', 'allocated_amount' => 1_000])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('date');
        } else {
            mcpWriter($budget->user)
                ->tool(UpdateBudgetPeriod::class, ['budget_id' => $budget->id, 'date' => '2026-10-20', 'allocated_amount' => 1_000])
                ->assertHasErrors(['That period has already closed; only the period in progress and future ones can change.']);
        }

        expect($budget->periods()->where('start_date', '2026-10-01')->sole()->allocated_amount)->toBe(10_000);
    })->with(['api', 'mcp']);

    it('opens a new budget on the owner\'s month, wherever it is created', function (string $timezone, string $now, string $start) {
        Carbon::setTestNow(Carbon::parse($now));
        $user = User::factory()->create(['timezone' => $timezone]);
        $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

        $created = app(BudgetManagementService::class)->create($user, $user->personalSpace, [
            'name' => 'Groceries',
            'period_type' => BudgetPeriodType::Monthly->value,
            'period_start_day' => 1,
            'rollover_type' => RolloverType::Reset->value,
            'allocated_amount' => 10_000,
        ], [$category->id], []);

        expect($created['period']->start_date->toDateString())->toBe($start);
    })->with([
        'Los Angeles, still October' => ['America/Los_Angeles', '2026-11-01 00:30:00', '2026-10-01'],
        'Auckland, already November' => ['Pacific/Auckland', '2026-10-31 12:00:00', '2026-11-01'],
    ]);
});

/**
 * The hourly schedule, run for real across the owner's midnight: exactly one
 * new period, no gap, no duplicate, nothing moved.
 */
describe('hour after hour', function () {
    /**
     * @return list<array{0: string, 1: string}>
     */
    function periodDatesOf(Budget $budget): array
    {
        return $budget->periods()->orderBy('start_date')->get()
            ->map(fn (BudgetPeriod $period): array => [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->all();
    }

    /**
     * Every period starts the day after the one before it ended.
     *
     * @param  list<array{0: string, 1: string}>  $dates
     */
    function chainIsContiguous(array $dates): bool
    {
        foreach ($dates as $index => [$start]) {
            if ($index > 0 && Carbon::parse($dates[$index - 1][1])->addDay()->toDateString() !== $start) {
                return false;
            }
        }

        return true;
    }

    it('rolls a month over once, at the hour of the owner\'s midnight', function (?string $timezone, string $hourBefore, string $midnightHour) {
        Carbon::setTestNow(Carbon::parse($hourBefore)->subHours(3));
        $budget = octoberAndNovemberBudget($timezone);
        $this->artisan('budgets:generate-periods')->assertSuccessful();

        $kept = periodDatesOf($budget);

        expect(array_column($kept, 0))->toBe(['2026-10-01', '2026-11-01', '2026-12-01']);

        for ($hour = Carbon::parse($hourBefore)->subHours(2); $hour->lte(Carbon::parse($hourBefore)); $hour->addHour()) {
            Carbon::setTestNow($hour->copy());
            $this->artisan('budgets:generate-periods')->assertSuccessful();
        }

        expect(periodDatesOf($budget))->toBe($kept);

        // A rerun in the same hour changes nothing the first run did not.
        Carbon::setTestNow(Carbon::parse($midnightHour));
        $this->artisan('budgets:generate-periods')->assertSuccessful();
        $this->artisan('budgets:generate-periods')->assertSuccessful();

        $rolled = periodDatesOf($budget);

        expect(array_column($rolled, 0))->toBe(['2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01'])
            ->and(array_slice($rolled, 0, 3))->toBe($kept)
            ->and(chainIsContiguous($rolled))->toBeTrue();

        for ($hour = Carbon::parse($midnightHour)->addHour(); $hour->lte(Carbon::parse($midnightHour)->addHours(3)); $hour->addHour()) {
            Carbon::setTestNow($hour->copy());
            $this->artisan('budgets:generate-periods')->assertSuccessful();
        }

        expect(periodDatesOf($budget))->toBe($rolled)
            ->and($budget->periods()->where('start_date', '2026-11-01')->sole()->carried_over_amount)->toBe(10_000);
    })->with([
        'Los Angeles, 00:00 PDT on the day the clocks go back' => ['America/Los_Angeles', '2026-11-01 06:00:00', '2026-11-01 07:00:00'],
        'Auckland' => ['Pacific/Auckland', '2026-10-31 10:00:00', '2026-10-31 11:00:00'],
        'Madrid' => ['Europe/Madrid', '2026-10-31 22:00:00', '2026-10-31 23:00:00'],
        'UTC' => ['UTC', '2026-10-31 23:00:00', '2026-11-01 00:00:00'],
        'no timezone' => [null, '2026-10-31 23:00:00', '2026-11-01 00:00:00'],
        'an invalid timezone' => ['Mars/Olympus_Mons', '2026-10-31 23:00:00', '2026-11-01 00:00:00'],
    ]);

    it('rolls a week over at the owner\'s midnight across a clock change', function (string $timezone, string $lastHour, string $midnightHour, string $previousStart, string $newStart) {
        $previous = Carbon::parse($previousStart);
        Carbon::setTestNow(Carbon::parse($lastHour)->subDay());
        $user = User::factory()->create(['onboarded_at' => now(), 'timezone' => $timezone]);
        // 1 is Monday.
        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'period_type' => BudgetPeriodType::Weekly,
            'period_start_day' => 1,
            'rollover_type' => RolloverType::Reset,
        ]);
        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $previous->toDateString(),
            'end_date' => $previous->copy()->addDays(6)->toDateString(),
            'allocated_amount' => 10_000,
        ]);

        Carbon::setTestNow(Carbon::parse($lastHour));
        expect($budget->fresh()->getCurrentPeriod()->start_date->toDateString())->toBe($previousStart);

        Carbon::setTestNow(Carbon::parse($midnightHour));
        expect($budget->fresh()->getCurrentPeriod())->toBeNull();

        $this->artisan('budgets:generate-periods')->assertSuccessful();
        $this->artisan('budgets:generate-periods')->assertSuccessful();

        $dates = periodDatesOf($budget);

        expect($budget->fresh()->getCurrentPeriod()->start_date->toDateString())->toBe($newStart)
            ->and(array_column($dates, 0))->toBe([
                $previousStart,
                $newStart,
                Carbon::parse($newStart)->addWeek()->toDateString(),
                Carbon::parse($newStart)->addWeeks(2)->toDateString(),
            ])
            ->and(chainIsContiguous($dates))->toBeTrue();
    })->with([
        // CEST ended at 01:00 UTC on 25 October: Monday opens at 23:00 UTC, not 22:00.
        'Madrid, autumn' => ['Europe/Madrid', '2026-10-25 22:59:00', '2026-10-25 23:00:00', '2026-10-19', '2026-10-26'],
        // CEST began at 01:00 UTC on 28 March 2027: Monday opens at 22:00 UTC, not 23:00.
        'Madrid, spring' => ['Europe/Madrid', '2027-03-28 21:59:00', '2027-03-28 22:00:00', '2027-03-22', '2027-03-29'],
        // NZDT ended on 4 April 2027: Monday opens at 12:00 UTC, not 11:00.
        'Auckland, autumn' => ['Pacific/Auckland', '2027-04-04 11:59:00', '2027-04-04 12:00:00', '2027-03-29', '2027-04-05'],
        // PST began on 1 November: Monday opens at 08:00 UTC, not 07:00.
        'Los Angeles, autumn' => ['America/Los_Angeles', '2026-11-02 07:59:00', '2026-11-02 08:00:00', '2026-10-26', '2026-11-02'],
    ]);

    it('leaves the dates and figures of stored periods alone', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-31 11:00:00'));
        $budget = octoberAndNovemberBudget('Pacific/Auckland');
        $before = $budget->periods()->orderBy('start_date')->get()->map->only(['id', 'start_date', 'end_date', 'allocated_amount'])->all();

        $this->artisan('budgets:generate-periods')->assertSuccessful();

        expect($budget->periods()->orderBy('start_date')->limit(2)->get()->map->only(['id', 'start_date', 'end_date', 'allocated_amount'])->all())->toEqual($before);
    });
});
