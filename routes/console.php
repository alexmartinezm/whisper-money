<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budgets:generate-periods')->daily();
Schedule::command('recurring:detect')->dailyAt('04:00');
Schedule::command('recurring:remind')->dailyAt('08:00');
Schedule::command('recurring:alert-shortfall')->dailyAt('08:15');
Schedule::command('recurring:alert-price-changes')->weeklyOn(1, '08:30');
Schedule::command('banking:sync')->everySixHours();
Schedule::command('banks:check-logos')->weekly();
// Splits are the one thing here with no other integrity net, and this command
// existed without ever running. The flag matters: without it the audit always
// exits zero, so a scheduled run would stay silent about whatever it found.
Schedule::command('transactions:audit-splits --fail-on-invalid')->dailyAt('03:30');
Schedule::command('banking:cancel-free-enablebanking')->lastDayOfMonth('18:00');
Schedule::command('real-estate:apply-revaluation')->monthlyOn(1, '00:00');
Schedule::command('loans:generate-balances')->monthlyOn(1, '00:00');
Schedule::command('email:paywall-follow-up')->dailyAt('10:00')->timezone('Europe/Madrid');
Schedule::command('email:ai-consent-follow-up')->dailyAt('10:15')->timezone('Europe/Madrid');
Schedule::command('stats:daily-report')->dailyAt('09:00')->timezone('Europe/Madrid');
Schedule::command('stats:ai-cohort-report')->monthlyOn(1, '09:00')->timezone('Europe/Madrid');
Schedule::command('stats:subscription-funnel')->weekly()->mondays()->at('09:15')->timezone('Europe/Madrid');
Schedule::command('stats:experiment-funnel')->weekly()->mondays()->at('09:30')->timezone('Europe/Madrid');
