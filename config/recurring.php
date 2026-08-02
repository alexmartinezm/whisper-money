<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature toggle
    |--------------------------------------------------------------------------
    |
    | The initial value of the `App\Features\RecurringTransactions` Pennant
    | flag. Per-user overrides stored by Pennant still take precedence.
    |
    | This is a full off switch, not just a way to hide the screen: with it off
    | the scheduled `recurring:detect` exits without writing anything, and the
    | command also skips individual users whose Pennant flag is inactive.
    |
    */
    'enabled' => (bool) env('RECURRING_TRANSACTIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Detection window
    |--------------------------------------------------------------------------
    |
    | How far back detection looks. It has to span `min_occurrences` of the
    | slowest cadence, so a yearly series needs two full years plus a margin —
    | at thirteen months the yearly case could never be reached at all.
    |
    | The cost of the wider window is that series which stopped billing a long
    | time ago are still detected, and land in the lapsed list.
    |
    */
    'lookback_months' => 25,

    /*
    |--------------------------------------------------------------------------
    | Minimum occurrences
    |--------------------------------------------------------------------------
    |
    | Two dates produce a single gap, which is not enough to tell a cadence from
    | a coincidence. Three occurrences give two gaps to agree with each other.
    |
    */
    'min_occurrences' => 3,

    /*
    |--------------------------------------------------------------------------
    | Merchant grouping
    |--------------------------------------------------------------------------
    |
    | Share of the corpus above which a description token counts as structural
    | noise and is dropped from the grouping key. Mirrors the AI suggestion
    | pipeline so both features group merchants the same way.
    |
    */
    'noise_token_fraction' => 0.02,

    /*
    |--------------------------------------------------------------------------
    | Cadence tolerances
    |--------------------------------------------------------------------------
    |
    | Each cadence matches when the median gap falls inside [min_days, max_days]
    | and the median absolute deviation of the gaps stays within max_deviation.
    | Order matters: the first match wins.
    |
    */
    'cadences' => [
        'weekly' => ['min_days' => 6, 'max_days' => 8, 'max_deviation' => 2],
        'biweekly' => ['min_days' => 12, 'max_days' => 16, 'max_deviation' => 3],
        'monthly' => ['min_days' => 27, 'max_days' => 32, 'max_deviation' => 4],
        'quarterly' => ['min_days' => 85, 'max_days' => 97, 'max_deviation' => 7],
        'yearly' => ['min_days' => 355, 'max_days' => 375, 'max_deviation' => 14],
    ],

    /*
    |--------------------------------------------------------------------------
    | Looking ahead
    |--------------------------------------------------------------------------
    |
    | How far the cash-flow projection runs, and how many days before a charge
    | its reminder goes out.
    |
    */
    'forecast_days' => 30,
    'reminder_lead_days' => 3,

    /*
    |--------------------------------------------------------------------------
    | Everyday spending
    |--------------------------------------------------------------------------
    |
    | Recurring charges are a minority of what leaves an account: groceries,
    | restaurants, fuel and presents are not subscriptions. A runway built from
    | detected charges alone is optimistic every single month, so it also
    | estimates the rest from what the user actually spends.
    |
    | The estimate is the median of the last `spending_lookback_months` complete
    | months of non-recurring, expense-side outflow. A median rather than a mean
    | so one unusual month cannot set the pace; complete months only, so the one
    | in progress does not read as a cheap month; and a minimum below which
    | nothing is shown at all, because a confident figure drawn from two weeks
    | of history is worse than admitting there is not enough to go on.
    |
    */
    'spending_lookback_months' => 3,
    'spending_min_months' => 3,

    /*
    |--------------------------------------------------------------------------
    | Outlook
    |--------------------------------------------------------------------------
    |
    | How many months past the forecast window to list. A quarterly premium or
    | an annual renewal never lands inside 30 days, so without this they are
    | detected but shown against no date at all.
    |
    | Only charges that do not come round every month appear here. Listing the
    | monthly ones too would restate the projection above twelve times over and
    | bury the irregular charges this list exists for.
    |
    */
    'outlook_months' => 12,

    /*
    |--------------------------------------------------------------------------
    | Amount stability
    |--------------------------------------------------------------------------
    |
    | A series whose amounts deviate by more than this share of the median is
    | reported as variable (utility bills) rather than fixed (subscriptions).
    |
    */
    'amount_variance_threshold' => 0.15,

    /*
    |--------------------------------------------------------------------------
    | Price changes
    |--------------------------------------------------------------------------
    |
    | A subscription that quietly goes up stays invisible: the expected amount
    | is a median over the whole history, so a rise only drags it slowly and
    | never announces itself. Detection compares the most recent
    | `price_window` charges against the `price_window` before them.
    |
    | The hard part is telling a step change from a bill that simply varies. A
    | rise has to clear `price_rise_threshold` and `price_rise_minimum` — the
    | second so a few cents on a small charge stays quiet — and, crucially, both
    | windows have to be steady in themselves: a spread wider than
    | `price_stability` of their own median means this is an electricity bill
    | doing what electricity bills do, not a price that moved.
    |
    */
    'price_window' => 3,
    'price_rise_threshold' => 0.10,
    'price_rise_minimum' => 100,
    'price_stability' => 0.10,

    /*
    |--------------------------------------------------------------------------
    | Lapse tolerance
    |--------------------------------------------------------------------------
    |
    | A series is lapsed once this many intervals have passed since the last
    | occurrence without a new one arriving.
    |
    | A series the user confirmed gets a longer rope: they have vouched for the
    | commitment, so one late renewal should not be reported as a cancellation.
    |
    */
    'lapse_interval_multiplier' => 1.5,
    'confirmed_lapse_interval_multiplier' => 2.5,
];
