<?php

namespace Botex\Bot\Job;

/**
 * Where a job is in its life.
 *
 * PENDING and RUNNING are the working states; the other three are
 * terminal until something changes them deliberately.
 */
enum JobStatus: string
{
    /** Waiting for its due time, or waiting to be retried. */
    case PENDING = 'pending';

    /** Claimed by the worker and running right now. */
    case RUNNING = 'running';

    /** Ran as many times as it was going to. */
    case DONE = 'done';

    /** Ran out of attempts. Needs a person to look at it. */
    case FAILED = 'failed';

    /** Held by hand, or by its extension going away. */
    case PAUSED = 'paused';

    /** Whether the worker will ever pick this up again. */
    public function isActive(): bool
    {
        return $this === self::PENDING || $this === self::RUNNING;
    }

    public function isTerminal(): bool
    {
        return $this === self::DONE || $this === self::FAILED;
    }

    /** Resuming only makes sense from a stopped state. */
    public function isResumable(): bool
    {
        return $this !== self::PENDING && $this !== self::RUNNING;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
