<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavedFilter;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BackfillSpaces extends Command
{
    protected $signature = 'spaces:backfill {--chunk=500 : Users processed per batch}';

    protected $description = 'Provision a personal space for every user and stamp their existing rows with it';

    /**
     * Owned tables to backfill. Each row inherits the personal space of its
     * user_id. Idempotent: only rows with a null space_id are touched.
     *
     * @var list<class-string<Model>>
     */
    private array $models = [
        Account::class,
        BankingConnection::class,
        Transaction::class,
        Category::class,
        Label::class,
        Budget::class,
        AutomationRule::class,
        SavedFilter::class,
    ];

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        $this->info('Provisioning personal spaces…');
        User::query()->whereNull('current_space_id')->chunkById($chunk, function ($users): void {
            foreach ($users as $user) {
                $user->provisionPersonalSpace();
            }
        });

        $this->info('Backfilling space_id on owned rows…');
        Space::query()->where('personal', true)->chunkById($chunk, function ($spaces): void {
            foreach ($spaces as $space) {
                foreach ($this->models as $model) {
                    $this->stamp($model, $space->owner_id, $space->id);
                }
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function stamp(string $model, string $ownerId, string $spaceId): void
    {
        $query = $model::query()->whereNull('space_id')->where('user_id', $ownerId);

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $query->update(['space_id' => $spaceId]);
    }
}
