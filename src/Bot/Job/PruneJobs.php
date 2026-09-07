<?php

namespace Botex\Bot\Job;

use Botex\Repository\JobRepository;

/**
 * Clears out finished job rows.
 *
 * The job table is the one thing guaranteed to grow on a busy bot, so
 * core keeps itself tidy using its own scheduler rather than a special
 * case inside the worker loop.
 */
class PruneJobs implements JobInterface
{
    /** Finished rows older than this are removed, unless data says otherwise. */
    public const KEEP_SECONDS = 604800;

    public function __construct(
        private JobRepository $jobs
    ) {
    }

    public static function name(): string
    {
        return 'prune';
    }

    public function handle(JobContext $context): void
    {
        $keep = $context->int('keep', self::KEEP_SECONDS);

        $this->jobs->prune($keep);
    }
}
