<?php

use App\Enums\CategoryType;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\Summaries;

it('freezes a month it can report and reads back the same figures', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    transactionIn($user, CategoryType::Income, 200000, closedMonth()->copy()->addDays(3));
    transactionIn($user, CategoryType::Expense, -50000, closedMonth()->copy()->addDays(5));

    $summary = app(Summaries::class)->freeze($user, closedMonth());

    expect($summary)->not->toBeNull()
        ->and($summary->period)->toBe(closedMonth()->format('Y-m'))
        ->and($summary->figure('currency'))->toBe('EUR')
        ->and($summary->figure('cashflow.income'))->toBe(200000)
        ->and($summary->figure('cashflow.expense'))->toBe(50000);

    // Asking again returns the stored row rather than building a second one.
    $again = app(Summaries::class)->freeze($user, closedMonth());

    expect($again->id)->toBe($summary->id)
        ->and(MonthlySummary::query()->count())->toBe(1);
});

it('reports nothing for a month with no transactions', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);

    expect(app(Summaries::class)->freeze($user, closedMonth()))->toBeNull()
        ->and(MonthlySummary::query()->count())->toBe(0);
});

it('leaves a settled month alone even when its figures change afterwards', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    transactionIn($user, CategoryType::Expense, -50000, closedMonth()->copy()->addDays(5));
    // Something in the month after: the month has settled, so the freeze is final.
    transactionIn($user, CategoryType::Expense, -100, now(), now());

    $summary = app(Summaries::class)->freeze($user, closedMonth());
    expect($summary->complete)->toBeTrue()
        ->and($summary->figure('cashflow.expense'))->toBe(50000);

    transactionIn($user, CategoryType::Expense, -70000, closedMonth()->copy()->addDays(6));

    // A closed month does not move under the reader, however much they add to it.
    expect(app(Summaries::class)->freeze($user, closedMonth())->figure('cashflow.expense'))
        ->toBe(50000);
});

it('rebuilds a month frozen before it settled, and drops the stale analysis', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    transactionIn($user, CategoryType::Expense, -50000, closedMonth()->copy()->addDays(5));

    // Nothing has happened since, so the month is still open.
    $first = app(Summaries::class)->freeze($user, closedMonth());
    expect($first->complete)->toBeFalse();

    $first->forceFill(['ai_analysis' => 'written against the old figures', 'ai_generated_at' => now()])->save();

    // The missing data arrives, and the month settles.
    transactionIn($user, CategoryType::Expense, -20000, closedMonth()->copy()->addDays(7));
    transactionIn($user, CategoryType::Expense, -100, now(), now());

    $second = app(Summaries::class)->freeze($user, closedMonth());

    expect($second->id)->toBe($first->id)
        ->and($second->complete)->toBeTrue()
        ->and($second->figure('cashflow.expense'))->toBe(70000)
        ->and($second->ai_analysis)->toBeNull()
        ->and($second->ai_generated_at)->toBeNull();
});
