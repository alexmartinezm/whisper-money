<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\User;
use App\Services\MonthlySummary\SummaryBuilder;

it('leaves an archived account out of the names the analysis may use', function (): void {
    // As-of the month, not today: a month that closed before the archiving
    // still belonged to the account, so it keeps its name there.
    $month = closedMonth();
    $user = User::factory()->create(['currency_code' => 'EUR']);

    $live = archivableAccount($user, 'Salary account', null);
    $archivedBefore = archivableAccount($user, 'Old savings', $month->copy()->subMonths(2));
    $archivedDuring = archivableAccount($user, 'Closed mid month', $month->copy()->addDays(10));
    $archivedAfter = archivableAccount($user, 'Archived later', $month->copy()->addMonths(1));
    $archivedOnLastDay = archivableAccount($user, 'Closed on the last day', $month->copy()->endOfMonth());

    transactionIn($user, CategoryType::Expense, -10000, $month->copy()->addDays(3));

    $payload = app(SummaryBuilder::class)->build($user, $month, complete: true);
    $names = implode(' | ', $payload['account_names']);

    expect($names)->toContain($live->name)
        ->and($names)->toContain($archivedAfter->name)
        ->and($names)->not->toContain($archivedBefore->name)
        ->and($names)->not->toContain($archivedDuring->name)
        ->and($names)->not->toContain($archivedOnLastDay->name);
});

function archivableAccount(User $user, string $name, ?Carbon\Carbon $archivedAt): Account
{
    return Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'name' => $name,
        'type' => AccountType::Savings,
        'archived_at' => $archivedAt,
    ]);
}
