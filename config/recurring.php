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
    | Lapse tolerance
    |--------------------------------------------------------------------------
    |
    | A series is lapsed once this many intervals have passed since the last
    | occurrence without a new one arriving.
    |
    */
    'lapse_interval_multiplier' => 1.5,
];
