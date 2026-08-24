<?php

namespace App\Support;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Str;
use PDOException;
use RedisException;
use Throwable;

/**
 * Whether the queue worker is between jobs right now.
 *
 * A `queue:work` daemon spends almost all of its life in a poll loop that talks
 * to the database and to the cache on every turn: it pops a job inside a
 * transaction, and it reads `illuminate:queue:restart` to decide whether to stop.
 * When a deploy takes MySQL or Redis away underneath it, both of those raise,
 * every worker raises at the same instant, and Laravel reports each one — which
 * is how a single container restart put five identical exceptions in the error
 * tracker and buried the failures that were actually about this application.
 *
 * The loop is the only place that noise is safe to drop, so this holds the two
 * facts that identify it. `Looping` fires once per turn of the daemon and never
 * in a web request, which is what keeps a lost connection in HTTP reportable;
 * `insideJob` reopens reporting for the duration of a job, because a connection
 * lost while a job is running is the application's problem and has to be seen.
 *
 * The queue events that drive it are wired in {@see AppServiceProvider},
 * and the reporting rule that reads it lives in `bootstrap/app.php`.
 */
class QueueWorkerLoop
{
    /**
     * The ways MySQL and Redis say "I am not there any more". Deliberately a
     * short list of the wordings a restart actually produces rather than every
     * connection error there is: anything not on it stays reportable.
     */
    private const array LOST_CONNECTION = [
        'server has gone away',
        'Lost connection',
        'Connection refused',
        'Connection reset by peer',
    ];

    private bool $inLoop = false;

    private bool $insideJob = false;

    /**
     * A turn of the daemon's poll loop has started. Also clears the job flag: a
     * worker that dies mid-job never fires `JobProcessed`, and the next turn is
     * proof the job is over either way.
     */
    public function enterLoop(): void
    {
        $this->inLoop = true;
        $this->insideJob = false;
    }

    public function enterJob(): void
    {
        $this->insideJob = true;
    }

    /**
     * The job finished cleanly. Left on purpose without clearing `inLoop`:
     * `stopIfNecessary()` runs after the job and before the next `Looping`, and
     * its cache read is one of the two calls this exists for.
     *
     * A job that *threw* must not come through here - the worker dispatches
     * `JobExceptionOccurred` before it reports, so this would silence the very
     * failures that have to be seen. The next turn's `enterLoop()` clears it.
     */
    public function leaveJob(): void
    {
        $this->insideJob = false;
    }

    /**
     * Whether we are in the daemon's poll loop with no job in flight.
     */
    public function isPolling(): bool
    {
        return $this->inLoop && ! $this->insideJob;
    }

    /**
     * Whether this exception is the poll loop finding its database or cache
     * gone — the one failure here that says nothing about this application,
     * because supervisor restarts the worker and the next turn works.
     *
     * Every clause narrows it: outside the loop, inside a job, or in a web
     * request this is false, so a lost connection anywhere that matters is
     * still reported.
     *
     * @api Invoked from the reporting rule in bootstrap/app.php, which static
     *      analysis does not scan.
     */
    public function isLostConnectionWhilePolling(Throwable $exception): bool
    {
        if (! $this->isPolling()) {
            return false;
        }

        if (! $exception instanceof PDOException && ! $exception instanceof RedisException) {
            return false;
        }

        return Str::contains($exception->getMessage(), self::LOST_CONNECTION, ignoreCase: true);
    }
}
