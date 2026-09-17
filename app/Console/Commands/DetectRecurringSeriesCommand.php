<?php

namespace App\Console\Commands;

use App\Features\RecurringTransactions;
use App\Models\User;
use App\Services\Recurring\DetectRecurringSeries;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

class DetectRecurringSeriesCommand extends Command
{
    protected $signature = 'recurring:detect
                            {--user= : Restrict the run to a single user id}
                            {--preview : Calculate reconciliation without writing anything}';

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

        $userId = $this->option('user');
        if ($userId !== null && ! Str::isUuid($userId)) {
            $this->components->error('The --user option must be a valid user UUID.');

            return self::FAILURE;
        }

        $query = User::query();

        if ($userId !== null) {
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
                    $series += $this->detectUser($user);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->components->error("User {$user->id}: {$exception->getMessage()}");
                }
            }
        });

        $message = $this->option('preview')
            ? "Previewed {$users} user(s); no recurring series were written."
            : "Scanned {$users} user(s), recorded {$series} recurring series.";
        $this->components->info($message);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function detectUser(User $user): int
    {
        if ($this->option('preview')) {
            $this->line(json_encode(
                $this->detector->previewForUserEverywhere($user),
                JSON_THROW_ON_ERROR,
            ));

            return 0;
        }

        return $this->detector->forUserEverywhere($user);
    }
}
