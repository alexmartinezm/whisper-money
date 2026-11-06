<?php

use App\Enums\AccountType;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalMonthStatus;
use App\Mail\MonthlySavingsGoalReminderEmail;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the month in progress is the owner's, not the server's.
 */

beforeEach(fn () => Mail::fake());

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A monthly goal of a user in $timezone, created on $createdAt with its
 * months up to then open.
 */
function timezoneGoal(?string $timezone, string $createdAt = '2026-10-05 12:00:00'): SavingsGoal
{
    Carbon::setTestNow(Carbon::parse($createdAt));
    $user = User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR', 'timezone' => $timezone]);
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal->fresh();
}

function timezoneSave(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings])->id,
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

describe('the month in progress', function () {
    it('is the owner\'s month', function (?string $timezone, string $now, string $expected, int $daysLeft) {
        $goal = timezoneGoal($timezone);
        Carbon::setTestNow(Carbon::parse($now));

        $stats = app(MonthlySavingsGoalStats::class)->forGoal($goal);

        expect($stats['current']['month'])->toBe($expected)
            ->and($stats['current']['days_left'])->toBe($daysLeft);
    })->with([
        'Buenos Aires, still 31 October' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10', 1],
        'UTC, already November' => ['UTC', '2026-11-01 00:30:00', '2026-11', 30],
        'Tokyo, already November' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11', 30],
        'UTC, still October' => ['UTC', '2026-10-31 16:00:00', '2026-10', 1],
        'no timezone falls back to the app' => [null, '2026-11-01 00:30:00', '2026-11', 30],
        'an invalid timezone falls back to the app' => ['Mars/Olympus_Mons', '2026-11-01 00:30:00', '2026-11', 30],
        'Los Angeles, still 31 October' => ['America/Los_Angeles', '2026-11-01 00:30:00', '2026-10', 1],
        'Auckland, already November' => ['Pacific/Auckland', '2026-10-31 12:00:00', '2026-11', 30],
        'Madrid, already November' => ['Europe/Madrid', '2026-10-31 23:30:00', '2026-11', 30],
    ]);

    it('keeps October unjudged until it is over for the owner', function () {
        $goal = timezoneGoal('America/Argentina/Buenos_Aires');
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $history = app(MonthlySavingsGoalStats::class)->forGoal($goal)['history'];

        expect(array_column($history, 'month'))->toBe(['2026-10'])
            ->and($history[0]['status'])->toBe(SavingsGoalMonthStatus::InProgress);
    });

    it('opens a goal created on the owner\'s last evening of the month in that month, as a partial one', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-01 01:00:00'));
        $user = User::factory()->create(['onboarded_at' => now(), 'timezone' => 'America/Argentina/Buenos_Aires']);

        $goal = app(SavingsGoalService::class)->createFromInput($user, [
            'kind' => 'monthly',
            'name' => 'Emergency fund',
            'monthly_target_type' => 'amount',
            'monthly_target_amount' => 30000,
        ]);

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($goal->createdMonth()->toDateString())->toBe('2026-10-01')
            ->and($goal->isPartialMonth(Carbon::parse('2026-10-01')))->toBeTrue();

        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00'));
        $history = app(MonthlySavingsGoalStats::class)->forGoal($goal->fresh())['history'];

        expect(array_column($history, 'month'))->toBe(['2026-10', '2026-11'])
            ->and($history[0]['status'])->toBe(SavingsGoalMonthStatus::Partial);
    });
});

describe('a closed month', function () {
    it('keeps its target when the goal is edited after it is over for the owner', function () {
        $goal = timezoneGoal('Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-10-31 11:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00'));
        $goal->refresh()->update(['monthly_target_amount' => 99_000]);
        app(SavingsGoalPeriodService::class)->syncCurrentPeriod($goal);

        expect($goal->periods()->where('month', '2026-10-01')->sole()->target_amount)->toBe(30_000)
            ->and($goal->periods()->where('month', '2026-11-01')->sole()->target_amount)->toBe(99_000);
    });
});

/*
 * Goals from before months were read on the owner's calendar: their periods
 * were opened on the UTC month, and nothing here may add to, move or rejudge
 * them. A Los Angeles goal created at 02:00 UTC on 1 November has November as
 * its first month although it was still October there; an Auckland one created
 * at 20:00 UTC on 31 October has October although it was already November.
 */
describe('a goal whose first month was opened on UTC', function () {
    function goalOpenedOnUtc(string $timezone, string $createdAt, string $month): SavingsGoal
    {
        Carbon::setTestNow(Carbon::parse($createdAt));
        $user = User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR', 'timezone' => $timezone]);
        $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);
        SavingsGoalPeriod::query()->create([
            'savings_goal_id' => $goal->id,
            'month' => $month,
            'target_type' => MonthlyTargetType::Amount,
            'target_amount' => 30_000,
            'resolved_target_amount' => 30_000,
        ]);

        return $goal->fresh();
    }

    it('opens no month behind it west of UTC', function () {
        $goal = goalOpenedOnUtc('America/Los_Angeles', '2026-11-01 02:00:00', '2026-11-01');

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-11-01'])
            ->and(array_column(app(MonthlySavingsGoalStats::class)->forGoal($goal->fresh())['history'], 'month'))->toBe(['2026-11']);
    });

    it('keeps that first month unjudged east of UTC', function () {
        $goal = goalOpenedOnUtc('Pacific/Auckland', '2026-10-31 20:00:00', '2026-10-01');

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));
        $history = app(MonthlySavingsGoalStats::class)->forGoal($goal)['history'];

        expect(array_column($history, 'month'))->toBe(['2026-10', '2026-11'])
            ->and($history[0]['status'])->toBe(SavingsGoalMonthStatus::Partial);
    });
});

describe('the budgets page', function () {
    it('counts last month on the owner\'s calendar', function (?string $timezone, string $expected) {
        $goal = timezoneGoal($timezone, '2026-09-05 12:00:00');
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));

        $this->actingAs($goal->user)->get('/budgets')
            ->assertInertia(fn ($page) => $page->where('monthlySavingsLastMonth.month', $expected));
    })->with([
        'Buenos Aires' => ['America/Argentina/Buenos_Aires', '2026-09'],
        'UTC' => ['UTC', '2026-10'],
    ]);
});

describe('savings-goals:generate-periods', function () {
    it('closes October only once October is over for the owner', function () {
        $goal = timezoneGoal('America/Argentina/Buenos_Aires');
        timezoneSave($goal, 31000, '2026-10-20');

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($goal->periods()->sole()->closed_at)->toBeNull();

        Carbon::setTestNow(Carbon::parse('2026-11-01 03:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        $october = $goal->periods()->where('month', '2026-10-01')->sole();

        expect($october->closed_at)->not->toBeNull()
            ->and($goal->periods()->where('month', '2026-11-01')->exists())->toBeTrue();
    });

    it('closes October at the same instant for a UTC owner', function (?string $timezone) {
        $goal = timezoneGoal($timezone);

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->where('month', '2026-10-01')->sole()->closed_at)->not->toBeNull();
    })->with(['UTC' => 'UTC', 'no timezone' => null, 'an invalid timezone' => 'Mars/Olympus_Mons']);

    it('closes October for a Tokyo owner while it is still October in UTC', function () {
        $goal = timezoneGoal('Asia/Tokyo');

        Carbon::setTestNow(Carbon::parse('2026-10-31 16:00:00'));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->where('month', '2026-10-01')->sole()->closed_at)->not->toBeNull()
            ->and($goal->periods()->where('month', '2026-11-01')->exists())->toBeTrue();
    });

    it('sends the month-end reminder at 07:00 on the owner\'s clock, once', function () {
        timezoneGoal('America/Argentina/Buenos_Aires');

        // 07:00 UTC on 28 October is 04:00 in Buenos Aires.
        Carbon::setTestNow(Carbon::parse('2026-10-28 07:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertNothingOutgoing();

        foreach (['10:00', '11:00', '23:00'] as $hour) {
            Carbon::setTestNow(Carbon::parse("2026-10-28 {$hour}:00"));
            $this->artisan('savings-goals:generate-periods');
        }

        Mail::assertQueuedCount(1);
    });

    it('sends the reminder on the owner\'s first day of the window, even if UTC has not reached it', function () {
        timezoneGoal('Asia/Tokyo');

        // 22:00 UTC on 26 October is 07:00 on 27 October in Tokyo, the first
        // of the last five days.
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:00:00'));
        $this->artisan('savings-goals:generate-periods');

        Mail::assertQueuedCount(1);
    });

    it('keeps the 07:00 UTC reminder for a user without a timezone', function () {
        timezoneGoal(null);

        Carbon::setTestNow(Carbon::parse('2026-10-28 06:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertNothingOutgoing();

        Carbon::setTestNow(Carbon::parse('2026-10-28 07:00:00'));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertQueuedCount(1);
    });

    it('opens one month and closes one, at the hour of the owner\'s midnight, and leaves them alone after', function (string $timezone, string $hourBefore, string $midnightHour) {
        $goal = timezoneGoal($timezone);

        Carbon::setTestNow(Carbon::parse($hourBefore));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        expect($goal->periods()->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01'])
            ->and($goal->periods()->sole()->closed_at)->toBeNull();

        // A rerun in the same hour opens and closes nothing more.
        Carbon::setTestNow(Carbon::parse($midnightHour));
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();
        $this->artisan('savings-goals:generate-periods')->assertSuccessful();

        $october = $goal->periods()->where('month', '2026-10-01')->sole();

        expect($october->closed_at->toDateTimeString())->toBe($midnightHour)
            ->and($goal->periods()->orderBy('month')->pluck('month')->map->toDateString()->all())->toBe(['2026-10-01', '2026-11-01']);

        foreach ([1, 2, 3] as $hours) {
            Carbon::setTestNow(Carbon::parse($midnightHour)->addHours($hours));
            $this->artisan('savings-goals:generate-periods')->assertSuccessful();
        }

        $frozen = $goal->periods()->where('month', '2026-10-01')->sole();

        expect($goal->periods()->count())->toBe(2)
            ->and($frozen->getAttributes())->toBe($october->getAttributes())
            ->and($goal->periods()->where('month', '2026-11-01')->sole()->closed_at)->toBeNull();
    })->with([
        'Los Angeles, 00:00 PDT on the day the clocks go back' => ['America/Los_Angeles', '2026-11-01 06:00:00', '2026-11-01 07:00:00'],
        'Auckland' => ['Pacific/Auckland', '2026-10-31 10:00:00', '2026-10-31 11:00:00'],
        'Madrid' => ['Europe/Madrid', '2026-10-31 22:00:00', '2026-10-31 23:00:00'],
    ]);

    it('sends the reminder at 07:00 on the owner\'s clock on both sides of a clock change', function (string $timezone, string $hourBefore, string $reminderHour) {
        timezoneGoal($timezone);

        Carbon::setTestNow(Carbon::parse($hourBefore));
        $this->artisan('savings-goals:generate-periods');
        Mail::assertNothingOutgoing();

        Carbon::setTestNow(Carbon::parse($reminderHour));
        $this->artisan('savings-goals:generate-periods');
        $this->artisan('savings-goals:generate-periods');
        Mail::assertQueuedCount(1);
    })->with([
        // CET since 25 October, so 07:00 is 06:00 UTC, an hour ahead of the old fixed run.
        'Madrid' => ['Europe/Madrid', '2026-10-27 05:00:00', '2026-10-27 06:00:00'],
        'Auckland' => ['Pacific/Auckland', '2026-10-26 17:00:00', '2026-10-26 18:00:00'],
        'Los Angeles' => ['America/Los_Angeles', '2026-10-27 13:00:00', '2026-10-27 14:00:00'],
    ]);

    it('holds the reminder through the owner\'s night', function () {
        timezoneGoal('America/Los_Angeles');

        // 08:00 on the first of the last five days in UTC, 01:00 in Los Angeles.
        Carbon::setTestNow(Carbon::parse('2026-10-27 08:00:00'));
        $this->artisan('savings-goals:generate-periods');

        Mail::assertNotQueued(MonthlySavingsGoalReminderEmail::class);
    });

    it('is scheduled hourly with a lock that expires within the hour', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'savings-goals:generate-periods'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue()
            ->and($events->first()->expiresAt)->toBe(60);
    });
});
