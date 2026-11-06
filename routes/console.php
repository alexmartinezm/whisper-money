<?php

use App\Models\ApiRequest;
use App\Models\Import;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Sentry\State\Scope;

use function Sentry\configureScope;

// Hourly, because a period ends at the owner's midnight, not the server's:
// for a user in Los Angeles that is 07:00 or 08:00 UTC, for one in Auckland
// 11:00 or 12:00 UTC the day before. A daily run at 00:00 UTC rolled periods
// over hours late (or a day late) for most users. Every run is idempotent: a
// period is found on its (budget, start date) key, and closing one writes the
// same carry-over to the same row.
Schedule::command('budgets:generate-periods')->hourly()->withoutOverlapping(60);
// Hourly, because a month is over at each owner's midnight and the month-end
// reminder waits for 07:00 on the owner's clock: hourly is the coarsest schedule
// that closes every timezone's month, and reminds it, within the hour (pages
// show the new month themselves if they get there first). Every run is
// idempotent: a month opens once on its (goal, month) key, and closing and the
// reminder are each claimed on the period, so each happens once a month.
Schedule::command('savings-goals:generate-periods')->hourly()->withoutOverlapping(60);
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
Schedule::command('email:inactive-no-bank')->dailyAt('09:45')->timezone('Europe/Madrid');
Schedule::command('email:connection-expiring')->dailyAt('09:50')->timezone('Europe/Madrid');
// Both of these mail REPORT_RECIPIENTS and, with nobody to mail, exit non-zero
// by design so an operator running them by hand is told. On a schedule that
// same exit is an exception a day about a setting, so skip instead: an unset
// REPORT_RECIPIENTS is a deployment that has not been finished, not a failure.
$hasReportRecipients = fn (): bool => config('mail.report_recipients') !== [];

Schedule::command('email:user-emails-report')->monthlyOn(1, '09:05')->timezone('Europe/Madrid')
    ->when($hasReportRecipients);
Schedule::command('banking:health --email')->dailyAt('09:30')->timezone('Europe/Madrid')
    ->when($hasReportRecipients);
Schedule::command('stats:daily-report')->dailyAt('09:00')->timezone('Europe/Madrid');
Schedule::command('stats:ai-cohort-report')->monthlyOn(1, '09:00')->timezone('Europe/Madrid');
Schedule::command('stats:subscription-funnel')->weekly()->mondays()->at('09:15')->timezone('Europe/Madrid');
// A full import that never got started keeps the rows the browser staged for
// it; a day on, nobody is coming back to finish that upload.
Schedule::command('model:prune', ['--model' => [Import::class]])->dailyAt('04:45')->timezone('Europe/Madrid');
// `/api/v1` usage rows past the 90 days `stats:api-usage` can usefully look
// back over (see ApiRequest::prunable()).
Schedule::command('model:prune', ['--model' => [ApiRequest::class]])->dailyAt('04:50')->timezone('Europe/Madrid');

/**
 * Make a failed scheduled command say which one it was and why it failed.
 *
 * The scheduler throws from inside Laravel, so every failure carries the same
 * stack trace and the command's own output has already been discarded: the error
 * tracker gets `Scheduled command [...] failed with exit code [1]` and nothing
 * else. Two unrelated failures - a split audit finding anomalies, and a bank
 * report with nowhere to send itself - spent three weeks filed as one issue
 * because of it.
 *
 * These callbacks run inside `Event::run()`, before the scheduler throws and in
 * the same process, so the log line lands as a breadcrumb on the event and the
 * scope travels with it. The fingerprint is what files each command separately.
 *
 * Registered in a loop rather than on each line above so that adding a task
 * cannot forget it - and so this file stays easy to merge from upstream.
 */
foreach (Schedule::events() as $event) {
    $name = Str::of((string) $event->command)->after("'artisan' ")->trim()->value();

    $event->onFailureWithOutput(function (Stringable $output) use ($event, $name): void {
        Log::error("Scheduled command [{$name}] failed.", [
            'exit_code' => $event->exitCode,
            'output' => $output->trim()->value(),
        ]);

        configureScope(function (Scope $scope) use ($event, $name, $output): void {
            $scope->setTag('scheduled_command', $name);
            $scope->setContext('scheduled_command', [
                'command' => $name,
                'exit_code' => $event->exitCode,
                'output' => $output->trim()->value(),
            ]);
            $scope->setFingerprint(['scheduled-command-failed', $name]);
        });
    });
}
