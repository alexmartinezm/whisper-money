<?php

namespace App\Console\Commands;

use App\Features\RecurringTransactions;
use App\Mail\RunwayShortfallEmail;
use App\Models\User;
use App\Services\Recurring\ProjectCashflow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Throwable;

class SendRunwayShortfallAlertsCommand extends Command
{
    protected $signature = 'recurring:alert-shortfall';

    protected $description = 'Warn users whose projected balance dips below zero before payday';

    public function __construct(private readonly ProjectCashflow $cashflow)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('recurring.enabled')) {
            $this->components->info('Recurring detection is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failures = 0;

        User::query()
            ->whereHas('setting', fn ($query) => $query->where('notify_runway_shortfall', true))
            ->chunkById(50, function ($users) use (&$sent, &$failures): void {
                foreach ($users as $user) {
                    if (! Feature::for($user)->active(RecurringTransactions::class)) {
                        continue;
                    }

                    try {
                        $sent += $this->warnAboutShortfall($user) ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->components->error("User {$user->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Sent {$sent} shortfall warning(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Warn once per dip, not once per day.
     *
     * The projection is rebuilt nightly and reports the same shortfall every
     * morning until the day arrives, so a level-triggered alert would send the
     * same warning a dozen times and get switched off. Recording the projected
     * low date makes it edge-triggered: silent while the announced dip is still
     * ahead, and speaking again only once it has passed and the balance is
     * still heading under.
     */
    private function warnAboutShortfall(User $user): bool
    {
        $forecast = $this->cashflow->forUser($user);
        $setting = $user->setting;

        // Nothing counted means the walk starts from zero and every charge
        // drives it negative. Warning on that would be warning about the
        // absence of data, which the screen deliberately stays quiet about too.
        if ($forecast['accounts'] === []) {
            return false;
        }

        $spending = $forecast['spending'];
        $lowest = $spending['lowest'] ?? $forecast['lowest'];

        if ($lowest['balance'] >= 0) {
            // Recovered: forget the dip so a future one is announced afresh.
            if ($setting?->runway_alerted_low_on !== null) {
                $setting->forceFill(['runway_alerted_low_on' => null])->saveQuietly();
            }

            return false;
        }

        $announced = $setting?->runway_alerted_low_on;

        if ($announced !== null && $announced->toDateString() >= CarbonImmutable::today()->toDateString()) {
            return false;
        }

        Mail::to($user->email)->send(new RunwayShortfallEmail(
            $user,
            $lowest,
            $forecast['currency_code'],
            $spending !== null,
        ));

        $setting?->forceFill(['runway_alerted_low_on' => $lowest['date']])->saveQuietly();

        return true;
    }
}
