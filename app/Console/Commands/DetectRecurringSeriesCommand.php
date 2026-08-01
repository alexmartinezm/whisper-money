<?php

namespace App\Console\Commands;

use App\Features\RecurringTransactions;
use App\Models\User;
use App\Services\Recurring\DetectRecurringSeries;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Throwable;

class DetectRecurringSeriesCommand extends Command
{
    protected $signature = 'recurring:detect
                            {--user= : Restrict the run to a single user id}';

    protected $description = 'Detect recurring charges from transaction history';

    public function __construct(private readonly DetectRecurringSeries $detector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // The flag is a full off switch, not just a way to hide the screen:
        // with it off nothing should be written that nobody can look at.
        if (! config('recurring.enabled')) {
            $this->components->info('Recurring detection is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $query = User::query();

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $users = 0;
        $series = 0;
        $failures = 0;

        // Chunked so one large deployment does not load every user into memory.
        $query->chunkById(50, function ($chunk) use (&$users, &$series, &$failures): void {
            foreach ($chunk as $user) {
                if (! Feature::for($user)->active(RecurringTransactions::class)) {
                    continue;
                }

                $users++;

                try {
                    $series += $this->detector->forUserEverywhere($user);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->components->error("User {$user->id}: {$exception->getMessage()}");
                }
            }
        });

        $this->components->info("Scanned {$users} user(s), recorded {$series} recurring series.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
