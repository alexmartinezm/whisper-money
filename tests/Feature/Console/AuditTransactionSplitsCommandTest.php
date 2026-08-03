<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function auditSplitFixture(): array
{
    $transaction = Transaction::factory()->create(['amount' => -10000, 'category_id' => null]);
    $categories = collect([1, 2])->map(fn () => Category::factory()->create([
        'user_id' => $transaction->user_id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]));
    $splits = collect([-6000, -4000])->map(fn (int $amount, int $position) => TransactionSplit::factory()->create([
        'transaction_id' => $transaction->id,
        'category_id' => $categories[$position]->id,
        'amount' => $amount,
        'position' => $position,
    ]));

    return [$transaction, $categories, $splits];
}

it('audits valid splits without writing', function () {
    [$transaction, , $splits] = auditSplitFixture();
    $before = [
        'parent' => $transaction->updated_at->toISOString(),
        'lines' => $splits->map->getRawOriginal()->all(),
    ];

    $this->artisan('transactions:audit-splits', ['--json' => true])->assertSuccessful();

    expect($transaction->fresh()->updated_at->toISOString())->toBe($before['parent'])
        ->and(TransactionSplit::query()->where('transaction_id', $transaction->id)->orderBy('position')->get()->map->getRawOriginal()->all())->toEqual($before['lines']);
});

it('reports stable anomaly codes and fails only when requested', function () {
    [$transaction, $categories, $splits] = auditSplitFixture();
    DB::table('transaction_splits')->where('id', $splits[0]->id)->update(['amount' => 0]);
    DB::table('transactions')->where('id', $transaction->id)->update(['category_id' => $categories[0]->id]);

    expect(Artisan::call('transactions:audit-splits', ['--json' => true]))->toBe(0);
    expect(Artisan::output())->toContain('parent_classification_present', 'split_amount_zero', 'split_sum_mismatch');

    $this->artisan('transactions:audit-splits', ['--fail-on-invalid' => true])->assertFailed();
});

it('reports AI suggestion provenance left on split parents', function () {
    [$transaction] = auditSplitFixture();
    DB::table('transactions')->where('id', $transaction->id)->update([
        'ai_suggested_category_id' => Category::factory()->create([
            'user_id' => $transaction->user_id,
            'space_id' => $transaction->space_id,
            'type' => CategoryType::Expense,
        ])->id,
        'ai_suggested_category_at' => now(),
    ]);

    expect(Artisan::call('transactions:audit-splits', ['--json' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('parent_classification_present');
});

it('audits restorable soft-deleted split parents', function () {
    [$transaction, , $splits] = auditSplitFixture();
    DB::table('transactions')->where('id', $transaction->id)->update(['deleted_at' => now()]);
    DB::table('transaction_splits')->where('id', $splits[0]->id)->update(['amount' => -5000]);

    expect(Artisan::call('transactions:audit-splits', ['--json' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('split_sum_mismatch');
});

it('reports ownership type soft delete sign and position anomalies', function () {
    [$transaction, $categories, $splits] = auditSplitFixture();
    DB::table('transaction_splits')->where('id', $splits[0]->id)->update(['position' => 4]);
    DB::table('transaction_splits')->where('id', $splits[1]->id)->update(['amount' => 4000]);
    DB::table('categories')->where('id', $categories[0]->id)->update(['deleted_at' => now()]);
    DB::table('categories')->where('id', $categories[1]->id)->update(['type' => CategoryType::Income->value, 'user_id' => Category::factory()->create()->user_id]);

    expect(Artisan::call('transactions:audit-splits', ['--json' => true]))->toBe(0);
    expect(Artisan::output())->toContain(
        'category_missing_or_deleted',
        'category_owner_or_space_mismatch',
        'category_type_invalid_or_mixed',
        'position_not_contiguous',
        'split_sign_mismatch',
    );
});

it('reports split lines left without a parent transaction', function () {
    [$transaction, , $splits] = auditSplitFixture();

    // The cascade normally makes this unreachable, so orphan the lines behind
    // the foreign key to prove the audit still surfaces them.
    Schema::withoutForeignKeyConstraints(function () use ($transaction): void {
        DB::table('transactions')->where('id', $transaction->id)->delete();
    });

    expect(Artisan::call('transactions:audit-splits', ['--json' => true]))->toBe(0);

    $report = json_decode(Artisan::output(), true);

    expect($report['anomaly_count'])->toBe(2)
        ->and(collect($report['anomalies'])->pluck('code')->all())->toBe(['parent_missing', 'parent_missing'])
        ->and(collect($report['anomalies'])->pluck('id')->sort()->values()->all())
        ->toBe($splits->pluck('id')->sort()->values()->all());

    $this->artisan('transactions:audit-splits', ['--fail-on-invalid' => true])->assertFailed();
});

it('logs the anomaly breakdown so a scheduled failure says what broke', function () {
    [$transaction, $categories] = auditSplitFixture();
    DB::table('transactions')->where('id', $transaction->id)->update(['category_id' => $categories[0]->id]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($transaction): bool {
            return $message === 'Transaction split audit found anomalies'
                && $context['parents_reviewed'] === 1
                && $context['lines_reviewed'] === 2
                && $context['anomaly_count'] === 1
                && $context['counts_by_code'] === ['parent_classification_present' => 1]
                && $context['ids_by_code'] === ['parent_classification_present' => [$transaction->id]];
        });

    $this->artisan('transactions:audit-splits', ['--fail-on-invalid' => true])->assertFailed();
});

it('stays quiet when the audit finds nothing', function () {
    auditSplitFixture();

    Log::spy();

    $this->artisan('transactions:audit-splits', ['--fail-on-invalid' => true])->assertSuccessful();

    Log::shouldNotHaveReceived('error');
});
