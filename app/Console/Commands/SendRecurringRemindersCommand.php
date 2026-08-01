<?php

namespace App\Console\Commands;

use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Features\RecurringTransactions;
use App\Mail\UpcomingRecurringChargesEmail;
use App\Models\RecurringSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Throwable;

class SendRecurringRemindersCommand extends Command
{
    protected $signature = 'recurring:remind';

    protected $description = 'Email users a digest of recurring charges due shortly';

    public function handle(): int
    {
        if (! config('recurring.enabled')) {
            $this->components->info('Recurring detection is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $horizon = CarbonImmutable::today()->addDays((int) config('recurring.reminder_lead_days'));
        $sent = 0;
        $failures = 0;

        User::query()
            ->whereHas('setting', fn ($query) => $query->where('notify_upcoming_recurring', true))
            ->chunkById(50, function ($users) use ($horizon, &$sent, &$failures): void {
                foreach ($users as $user) {
                    if (! Feature::for($user)->active(RecurringTransactions::class)) {
                        continue;
                    }

                    try {
                        $sent += $this->remind($user, $horizon) ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->components->error("User {$user->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Sent {$sent} reminder digest(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Send one digest for everything due inside the window that has not been
     * announced yet, and record which occurrence each reminder covered so a
     * daily run does not repeat itself.
     */
    private function remind(User $user, CarbonImmutable $horizon): bool
    {
        $due = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('status', RecurringSeriesStatus::Active)
            ->where('user_state', '!=', RecurringSeriesUserState::Ignored)
            ->whereDate('next_expected_on', '>=', CarbonImmutable::today()->toDateString())
            ->whereDate('next_expected_on', '<=', $horizon->toDateString())
            ->where(function ($query): void {
                $query->whereNull('last_reminded_on')
                    ->orWhereColumn('last_reminded_on', '!=', 'next_expected_on');
            })
            ->orderBy('next_expected_on')
            ->orderBy('display_name')
            ->get();

        if ($due->isEmpty()) {
            return false;
        }

        Mail::to($user->email)->send(new UpcomingRecurringChargesEmail($user, $due));

        // Store the occurrence that was announced, not the day it went out:
        // a reminder sent three days early must still silence this charge
        // tomorrow, and speak again for the next one.
        foreach ($due as $series) {
            $series->forceFill(['last_reminded_on' => $series->next_expected_on])->saveQuietly();
        }

        return true;
    }
}
