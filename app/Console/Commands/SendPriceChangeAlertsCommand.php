<?php

namespace App\Console\Commands;

use App\Features\RecurringTransactions;
use App\Mail\RecurringPriceChangesEmail;
use App\Models\User;
use App\Services\Recurring\DetectPriceChanges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Throwable;

class SendPriceChangeAlertsCommand extends Command
{
    protected $signature = 'recurring:alert-price-changes';

    protected $description = 'Email users when a detected subscription or bill has gone up';

    public function __construct(private readonly DetectPriceChanges $priceChanges)
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
            ->whereHas('setting', fn ($query) => $query->where('notify_price_changes', true))
            ->chunkById(50, function ($users) use (&$sent, &$failures): void {
                foreach ($users as $user) {
                    if (! Feature::for($user)->active(RecurringTransactions::class)) {
                        continue;
                    }

                    try {
                        $sent += $this->announce($user) ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->components->error("User {$user->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("Sent {$sent} price-change digest(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One digest covering everything that went up, then record the new price on
     * each series so a daily run does not repeat itself.
     *
     * Recording the announced amount rather than a date is what lets a second
     * rise speak: the series stays quiet at its new price forever, and only a
     * further move clears the bar again.
     */
    private function announce(User $user): bool
    {
        $rises = $this->priceChanges->forUser($user);

        if ($rises->isEmpty()) {
            return false;
        }

        Mail::to($user)->send(new RecurringPriceChangesEmail($user, $rises));

        foreach ($rises as $rise) {
            $rise->series->forceFill(['price_alerted_amount' => $rise->currentAmount])->saveQuietly();
        }

        return true;
    }
}
