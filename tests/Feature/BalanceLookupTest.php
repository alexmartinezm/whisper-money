<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
});

test('getBalanceAt returns carry-forward balance from before range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2025-12-15',
        'balance' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(100000);
});

test('getBalanceAt returns zero when no balance exists', function () {
    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(0);
});

test('getBalanceAt returns latest balance on or before date within range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-05',
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-20',
        'balance' => 200000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-03')))->toBe(0);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-05')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-20')))->toBe(200000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(200000);
});

test('getBalanceAt works with multiple accounts', function () {
    $account2 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => '2026-01-10',
        'balance' => 500000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id, $account2->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($account2->id, Carbon::parse('2026-01-15')))->toBe(500000);
});

test('getInvestedAmountAt returns null when no invested data exists', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($this->account->id, Carbon::parse('2026-01-15')))->toBeNull();
});

test('getInvestedAmountAt carries forward last known invested amount across null gaps', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-01-05',
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-01-20',
        'balance' => 550000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-05')))->toBe(400000);
    // After the null entry on Jan 20, invested_amount should still carry forward from Jan 5
    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-25')))->toBe(400000);
});

test('getInvestedAmountAt carries forward from before range', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-15',
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-15')))->toBe(400000);
});

test('getInvestedAmountAt carry-forward seed uses latest non-null invested amount before range', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    // Invested amount set early
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-01',
        'balance' => 400000,
        'invested_amount' => 300000,
    ]);
    // Latest balance before range has null invested_amount
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-20',
        'balance' => 450000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    // Should carry forward balance from Dec 20 (latest before range)
    expect($lookup->getBalanceAt($account->id, Carbon::parse('2026-01-10')))->toBe(450000);
    // Should carry forward invested_amount from Dec 1 (latest non-null before range)
    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-10')))->toBe(300000);
});

test('forAccounts handles empty account list', function () {
    $lookup = BalanceLookup::forAccounts(
        [],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt('nonexistent', Carbon::parse('2026-01-15')))->toBe(0);
    expect($lookup->getInvestedAmountAt('nonexistent', Carbon::parse('2026-01-15')))->toBeNull();
});

test('forAccounts accepts a Collection of account IDs', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        collect([$this->account->id]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
});

test('getBalanceAt carries a balance forward before, on, between and after its records', function () {
    foreach (['2025-12-15' => 100000, '2026-01-05' => 120000, '2026-01-20' => 90000] as $day => $balance) {
        AccountBalance::factory()->create([
            'account_id' => $this->account->id,
            'balance_date' => $day,
            'balance' => $balance,
        ]);
    }

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2025-12-14')))->toBe(0)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2025-12-15')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-04')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-05')))->toBe(120000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-19 23:59:59')))->toBe(120000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-20')))->toBe(90000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-01')))->toBe(90000);
});

test('getBalanceAt answers zero for an account without records next to one with them', function () {
    $empty = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
        'invested_amount' => 40000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id, $empty->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($empty->id, Carbon::parse('2026-01-15')))->toBe(0)
        ->and($lookup->getInvestedAmountAt($empty->id, Carbon::parse('2026-01-15')))->toBeNull()
        ->and($lookup->getBalanceAt('not-preloaded', Carbon::parse('2026-01-15')))->toBe(0)
        ->and($lookup->getInvestedAmountAt('not-preloaded', Carbon::parse('2026-01-15')))->toBeNull()
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
});

test('getBalanceAt scales a shared account to the owner\'s share on and between its records', function () {
    $this->account->forceFill(['ownership_applies_to_balance' => true, 'ownership_percentage' => 33])->save();

    foreach (['2025-12-20' => 1001, '2026-01-10' => 2003, '2026-01-25' => -999] as $day => $balance) {
        AccountBalance::factory()->create([
            'account_id' => $this->account->id,
            'balance_date' => $day,
            'balance' => $balance,
        ]);
    }

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    // 33% of 1001, 2003 and -999, each rounded on its own.
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2025-12-19')))->toBe(0)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-01')))->toBe(330)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-10')))->toBe(661)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-24')))->toBe(661)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-25')))->toBe(-330)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-15')))->toBe(-330);
});

test('getInvestedAmountAt scales a shared account and carries it over null days', function () {
    $this->account->forceFill(['ownership_applies_to_balance' => true, 'ownership_percentage' => 50])->save();

    foreach (['2026-01-05' => 1001, '2026-01-10' => null, '2026-01-20' => 3000] as $day => $invested) {
        AccountBalance::factory()->create([
            'account_id' => $this->account->id,
            'balance_date' => $day,
            'balance' => 5000,
            'invested_amount' => $invested,
        ]);
    }

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($this->account->id, Carbon::parse('2026-01-04')))->toBeNull()
        ->and($lookup->getInvestedAmountAt($this->account->id, Carbon::parse('2026-01-12')))->toBe(501)
        ->and($lookup->getInvestedAmountAt($this->account->id, Carbon::parse('2026-01-25')))->toBe(1500)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-12')))->toBe(2500);
});

test('getBalanceAt and getInvestedAmountAt agree with a day-by-day carry forward over a long range', function () {
    $balances = [];
    $invested = [];

    for ($offset = 0; $offset < 120; $offset += 4) {
        $day = Carbon::parse('2025-12-01')->addDays($offset)->toDateString();
        $balances[$day] = 10000 + $offset * 7;
        $invested[$day] = $offset % 8 === 0 ? 5000 + $offset : null;

        AccountBalance::factory()->create([
            'account_id' => $this->account->id,
            'balance_date' => $day,
            'balance' => $balances[$day],
            'invested_amount' => $invested[$day],
        ]);
    }

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-03-31'),
    );

    // From the range start (seeded by the records before it) to past its end.
    for ($offset = 31; $offset < 130; $offset++) {
        $date = Carbon::parse('2025-12-01')->addDays($offset);
        $day = $date->toDateString();
        $expectedBalance = 0;
        $expectedInvested = null;

        foreach ($balances as $recordedDay => $balance) {
            if ($recordedDay <= $day) {
                $expectedBalance = $balance;
                $expectedInvested = $invested[$recordedDay] ?? $expectedInvested;
            }
        }

        expect($lookup->getBalanceAt($this->account->id, $date))->toBe($expectedBalance, "balance on {$day}")
            ->and($lookup->getInvestedAmountAt($this->account->id, $date))->toBe($expectedInvested, "invested on {$day}");
    }
});

test('forAccounts reads balance rows without hydrating AccountBalance models', function () {
    foreach (['2025-12-15', '2026-01-05', '2026-01-20'] as $day) {
        AccountBalance::factory()->create([
            'account_id' => $this->account->id,
            'balance_date' => $day,
            'balance' => 100000,
            'invested_amount' => 50000,
        ]);
    }

    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.AccountBalance::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($hydrated)->toBe(0)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-25')))->toBe(100000);
});
