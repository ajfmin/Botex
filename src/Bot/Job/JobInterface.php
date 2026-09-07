<?php

namespace Botex\Bot\Job;

/**
 * One named piece of work the worker can run on a schedule.
 *
 * Implementations are resolved through Feeder, so constructor
 * dependencies are injected exactly as they are for commands, callbacks
 * and runnables. Extensions publish them from their jobs() hook.
 *
 * Nothing here takes an Update: a job runs long after the message that
 * scheduled it, and often with no message at all. Whatever it needs
 * travels in the job data.
 */
interface JobInterface
{
    /**
     * The stable name stored on the row.
     *
     * Renaming this orphans jobs already scheduled, which the worker then
     * pauses rather than runs.
     */
    public static function name(): string;

    /**
     * Runs the work.
     *
     * Throwing marks the attempt failed and schedules a retry, so a job
     * that hits a temporary problem should throw rather than swallow it.
     * Returning normally counts as success.
     */
    public function handle(JobContext $context): void;
}
