<?php

namespace Botex\Bot\Job;

use Botex\Broadcast\BroadcastService;
use Botex\Broadcast\Sender;

/**
 * Delivers whatever broadcast is going out, one slice per run.
 *
 * A standing job, armed once and left alone, exactly like the prune job
 * next to it. That shape is chosen over a job per broadcast for a reason
 * worth writing down: a handler cannot re-arm its own row. The worker
 * writes the run's outcome *after* handle() returns, so anything the
 * handler did to that row is overwritten, and clearing the lease to get
 * around it makes the worker report a duplicate it never had. Letting
 * the worker own the schedule and having this ask "is there anything to
 * send?" avoids the whole argument.
 *
 * So a broadcast is started by writing a row, not by scheduling
 * anything, and it stops by writing a different status. Pause, resume
 * and cancel are three UPDATEs, and none of them has to find a job.
 *
 * Each run sends for a bounded stretch and returns, which is what keeps
 * an announcement to a hundred thousand people from holding the single
 * worker process for two hours while every other schedule waits.
 */
class SendBroadcast implements JobInterface
{
    /** Seconds between looks when nothing is going out. */
    public const INTERVAL = 5;

    public function __construct(
        private BroadcastService $broadcasts,
        private Sender $sender
    ) {
    }

    public static function name(): string
    {
        return BroadcastService::JOB;
    }

    public function handle(JobContext $context): void
    {
        $broadcast = $this->broadcasts->running();

        // The common case by far: one indexed lookup that finds nothing.
        if ($broadcast === null) {
            return;
        }

        // Only moves a queued one. A broadcast already sending keeps the
        // audience total and start time from its first slice, so progress
        // is measured against one number for its whole life.
        $this->broadcasts->begin($broadcast);

        $fresh = $this->broadcasts->find((int) $broadcast->id);

        if ($fresh === null || !$fresh->status()->isActive()) {
            return;
        }

        // The heartbeat goes in so a worker whose lease lapsed stops
        // mid-slice, rather than two processes delivering the same
        // announcement to the same people.
        if ($this->sender->slice($fresh, static fn (): bool => $context->heartbeat())) {
            $this->broadcasts->finish($fresh);
        }
    }
}
