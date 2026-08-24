<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The scheduled entry whose artisan command starts with the given string.
 */
function scheduledEvent(string $command): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, "'artisan' {$command}"));

    expect($event)->not->toBeNull("No scheduled command matching [{$command}].");

    return $event;
}

test('skips the reports that mail REPORT_RECIPIENTS when nobody is configured', function (string $command) {
    config(['mail.report_recipients' => []]);

    expect(scheduledEvent($command)->filtersPass(app()))->toBeFalse();
})->with(['banking:health --email', 'email:user-emails-report']);

test('runs the reports that mail REPORT_RECIPIENTS once somebody is configured', function (string $command) {
    config(['mail.report_recipients' => ['owner@whisper.money']]);

    expect(scheduledEvent($command)->filtersPass(app()))->toBeTrue();
})->with(['banking:health --email', 'email:user-emails-report']);

/**
 * The guard belongs to those two alone: nothing else mails the owners, and a
 * task that stopped running because an unrelated setting is missing would be
 * worse than the alert this replaced.
 */
test('leaves every other scheduled task running regardless of recipients', function () {
    config(['mail.report_recipients' => []]);

    $skipped = collect(app(Schedule::class)->events())
        ->reject(fn (Event $event): bool => $event->filtersPass(app()))
        ->map(fn (Event $event): string => Str::of((string) $event->command)->after("'artisan' ")->trim()->value())
        ->sort()
        ->values()
        ->all();

    expect($skipped)->toBe(['banking:health --email', 'email:user-emails-report']);
});

test('every scheduled task says something when it fails', function () {
    Log::spy();

    $events = app(Schedule::class)->events();

    foreach ($events as $event) {
        $event->finish(app(), 1);
    }

    Log::shouldHaveReceived('error')->times(count($events));
});

/**
 * The failure this covers reached the error tracker as a bare "exit code [1]"
 * with the same stack trace as every other scheduled command, which is how two
 * unrelated failures ended up filed as one issue. The log line is what carries
 * the command and its output onto the event.
 */
test('a failed scheduled task logs which command failed and what it printed', function () {
    Log::spy();

    $event = scheduledEvent('banking:health --email');
    file_put_contents($event->output, 'REPORT_RECIPIENTS is not configured.');

    $event->finish(app(), 1);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'Scheduled command [banking:health --email] failed.'
            && $context['exit_code'] === 1
            && $context['output'] === 'REPORT_RECIPIENTS is not configured.',
    );
});

test('a scheduled task that succeeds logs nothing', function () {
    Log::spy();

    scheduledEvent('banking:health --email')->finish(app(), 0);

    Log::shouldNotHaveReceived('error');
});
