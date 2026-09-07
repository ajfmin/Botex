<?php

namespace Botex\Bot\Job;

/**
 * Thrown when a job is scheduled under a name no handler answers to.
 *
 * Raised at scheduling time, so the mistake surfaces in the code that
 * queued the job. The worker never throws this: a row whose handler has
 * since disappeared is paused, not fatal.
 */
class UnknownJob extends \InvalidArgumentException
{
    public static function for(string $extension, string $job): self
    {
        return new self("No job named '{$job}' is registered for '{$extension}'.");
    }
}
