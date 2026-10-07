<?php

namespace Botex\Bot\Job;

use Botex\Repository\JobRepository;
use Botex\Support\Config;

/**
 * Clears out finished job rows.
 *
 * The job table is the one thing guaranteed to grow on a busy bot, so
 * core keeps itself tidy using its own scheduler rather than a special
 * case inside the worker loop. Armed by the worker when it starts; see
 * Worker::armPrune().
 */
class PruneJobs implements JobInterface
{
    /** Finished rows older than this are removed, unless told otherwise. */
    public const KEEP_SECONDS = 604800;

    /** The standing row, keyed so there is only ever one. */
    public const KEY = JobRequest::CORE . ':prune';

    /** How often it looks; one indexed DELETE, so daily is plenty. */
    public const INTERVAL = 86400;

    public function __construct(
        private JobRepository $jobs,
        private Config $config
    ) {
    }

    public static function name(): string
    {
        return 'prune';
    }

    /**
     * Retention for finished rows, from jobs.keep_finished.
     *
     * Read on every run rather than stored on the row, so changing the
     * setting takes effect without anybody re-arming anything.
     */
    public static function keepSeconds(Config $config): int
    {
        return (int) $config->get('jobs.keep_finished', self::KEEP_SECONDS);
    }

    public function handle(JobContext $context): void
    {
        $keep = $context->int('keep', self::keepSeconds($this->config));

        // 0 means "never prune"; the worker stops arming the job, but a
        // row armed before the setting changed may still come round once.
        if ($keep <= 0) {
            return;
        }

        $this->jobs->prune($keep);
    }
}
