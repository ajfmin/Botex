<?php

namespace Botex\Bot\Job;

/**
 * How a job's next run is worked out.
 *
 * An enum rather than a bool so a third kind (cron, for instance) can be
 * added later without changing what is already stored.
 */
enum ScheduleType: string
{
    /** Runs a fixed number of times, then finishes. Usually once. */
    case ONCE = 'once';

    /** Re-arms itself a fixed number of seconds after each run. */
    case INTERVAL = 'interval';

    public function repeats(): bool
    {
        return $this === self::INTERVAL;
    }

    /** Human wording for the panel and the console. */
    public function label(): string
    {
        return match ($this) {
            self::ONCE => 'one time',
            self::INTERVAL => 'repeating',
        };
    }
}
