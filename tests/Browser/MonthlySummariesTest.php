<?php

declare(strict_types=1);

use App\Enums\CategoryType;
use App\Models\MonthlySummary;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * The month in review, in a browser.
 *
 * The HTTP side of this screen is already pinned by
 * tests/Feature/MonthlySummary, so what is asserted here is only what a browser
 * can prove: that the frozen figures reach the page as amounts a reader can
 * read, that the empty month offers to be analysed and that pressing the button
 * freezes it, and that the written analysis comes out as paragraphs rather than
 * one run-on line.
 */

beforeEach(function (): void {
    // Not what this screen is about, and the gate itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    // A frozen clock, so every month label below is the same one on every run
    // and on any day of the month. February 2026 is the month that closed.
    $this->travelTo('2026-03-10 09:00:00');
});

/**
 * A reader whose February closed, with euros for every figure.
 */
function summaryReader(): User
{
    return User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
    ]);
}

/**
 * A month frozen with fixed figures, so every amount below is known in advance.
 *
 * @param  array<string, mixed>  $attributes
 */
function frozenSummaryFor(User $user, ?string $period = null, array $attributes = []): MonthlySummary
{
    return MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'payload' => [
            'currency' => 'EUR',
            'has_history' => true,
            'net_worth' => ['current' => 16022305, 'diff' => 316500, 'diff_percent' => 2.0],
            'cashflow' => [
                'income' => 385000,
                'expense' => 248195,
                'net' => 136805,
                'savings_rate' => 35.5,
                'expense_change_percent' => -4.2,
            ],
            'categories' => [
                'total' => 248195,
                'top' => [
                    ['name' => 'Housing', 'amount' => 120000, 'share' => 48.3, 'change_percent' => 0.0],
                    ['name' => 'Groceries', 'amount' => 38000, 'share' => 15.3, 'change_percent' => 4.1],
                    ['name' => 'Restaurants', 'amount' => 15355, 'share' => 6.2, 'change_percent' => -12.5],
                ],
            ],
            'budgets' => ['total' => 6, 'met' => 4],
            'todos' => ['uncategorised' => ['count' => 12, 'amount' => 21480]],
        ],
        ...($period === null ? [] : ['period' => $period]),
        ...$attributes,
    ]);
}

it('opens on the month that closed and offers to analyse it when nothing is frozen', function (): void {
    $user = summaryReader();

    actingAs($user);

    $page = visit('/monthly-summary');

    $page->assertSee('Month in review')
        ->assertSee('February 2026')
        ->assertSee('This month has not been analysed yet. Nothing is computed until you ask.')
        ->assertSee('Analyse this month')
        ->assertDontSee('Where it went')
        ->assertNoJavascriptErrors();
});

it('freezes the month when asked and reads it straight back', function (): void {
    $user = summaryReader();
    transactionIn($user, CategoryType::Expense, -50000, closedMonth()->addDays(4));

    actingAs($user);

    $page = visit('/monthly-summary');

    $page->assertSee('Analyse this month')
        ->click('Analyse this month')
        ->assertPathIs('/monthly-summary')
        ->assertQueryStringHas('period', '2026-02')
        ->assertSee('February 2026')
        ->assertSee('Spent')
        ->assertSee('€500.00')
        ->assertDontSee('Analyse this month')
        ->assertNoJavascriptErrors();

    expect(MonthlySummary::query()->where('user_id', $user->id)->where('period', '2026-02')->exists())->toBeTrue();
});

it('reads a frozen month back as the figures it was frozen with', function (): void {
    $user = summaryReader();
    frozenSummaryFor($user);

    actingAs($user);

    $page = visit('/monthly-summary?period=2026-02');

    $page->assertSee('February 2026')
        // One tile per figure, each in the reader's currency.
        ->assertSee('Income')
        ->assertSee('€3,850.00')
        ->assertSee('Spent')
        ->assertSee('€2,481.95')
        ->assertSee('vs last month: -4.2%')
        ->assertSee('Net')
        ->assertSee('€1,368.05')
        ->assertSee('Savings rate: 35.5%')
        ->assertSee('Net worth')
        ->assertSee('€160,223.05')
        ->assertSee('+2.0%')
        // Where it went, biggest first.
        ->assertSee('Where it went')
        ->assertSee('Housing')
        ->assertSee('Groceries')
        ->assertSee('Restaurants')
        ->assertSee('€1,200.00')
        // The actionable half.
        ->assertSee('12 transactions are still uncategorised, worth €214.80.')
        // Every source had reported in, so the month does not say otherwise.
        ->assertDontSee('Still settling')
        ->assertNoJavascriptErrors();
});

it('says so when a month was frozen before every account had reported', function (): void {
    $user = summaryReader();
    frozenSummaryFor($user, '2026-01', ['complete' => false]);

    actingAs($user);

    $page = visit('/monthly-summary?period=2026-01');

    $page->assertSee('January 2026')
        ->assertSee('Still settling')
        ->assertSee('€3,850.00')
        ->assertNoJavascriptErrors();
});

it('prints the analysis as paragraphs when there is one, and says what is missing when there is not', function (): void {
    $user = summaryReader();
    frozenSummaryFor($user, '2026-02', [
        'ai_analysis' => "Restaurants fell because you ate in.\n\nHousing is the one to watch next month.",
        'ai_generated_at' => now(),
    ]);

    actingAs($user);

    $page = visit('/monthly-summary?period=2026-02');

    $page->assertSee('Restaurants fell because you ate in.')
        // Blank lines are paragraphs, not one run-on line.
        ->assertSee('Housing is the one to watch next month.')
        ->assertSee('Written from the figures below. It never sees individual transactions or merchants.')
        ->assertDontSee('Explain this month')
        ->assertNoJavascriptErrors();

    // The month whose analysis was never written keeps its figures. This reader
    // has not consented to AI, so the page says what asking for one would take
    // rather than offering a button that cannot deliver.
    frozenSummaryFor($user, '2026-01');

    $second = visit('/monthly-summary?period=2026-01');

    $second->assertSee('January 2026')
        ->assertSee('€3,850.00')
        ->assertSee('A written analysis needs a paid plan and AI enabled.')
        ->assertDontSee('Explain this month')
        ->assertDontSee('Written from the figures below.')
        ->assertNoJavascriptErrors();
});
