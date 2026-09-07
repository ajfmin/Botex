<?php

namespace Botex\Bot\Job;

use Botex\Extension\Registry;
use Botex\Support\Log\Logger;

/**
 * Allowlist of job handlers, keyed by "extension:job".
 *
 * A jobs row holds only those two names. Resolving them here, against
 * classes contributed by loaded code, means a tampered database row can
 * never cause an arbitrary class to be instantiated. It also means a
 * disabled extension's jobs resolve to nothing and are skipped rather
 * than run, without any extension-specific logic in the worker.
 */
class Jobs
{
    /**
     * Jobs that ship with core, addressed under JobRequest::CORE.
     *
     * @var array<class-string<JobInterface>>
     */
    private const CORE = [
        PruneJobs::class,
    ];

    /** @var array<string, class-string<JobInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions,
        private Logger $log
    ) {
    }

    /** @return array<string, class-string<JobInterface>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        $grouped = [JobRequest::CORE => self::CORE] + $this->extensions->jobs();

        foreach ($grouped as $slug => $classes) {
            foreach ($classes as $class) {
                if (!is_subclass_of($class, JobInterface::class)) {
                    $this->log->warning(
                        "Job {$class} does not implement JobInterface; skipped.",
                        ['extension' => $slug, 'handler' => $class]
                    );
                    continue;
                }

                // core first, so an extension cannot displace a core job
                $this->map[$slug . ':' . $class::name()] ??= $class;
            }
        }

        return $this->map;
    }

    /** @return class-string<JobInterface>|null */
    public function find(string $extension, string $job): ?string
    {
        return $this->all()[$extension . ':' . $job] ?? null;
    }

    /** @return class-string<JobInterface>|null */
    public function findByKey(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether a job can be scheduled right now.
     *
     * JobService calls this so a typo surfaces where the job is queued,
     * not minutes later inside the worker.
     */
    public function exists(string $extension, string $job): bool
    {
        return $this->find($extension, $job) !== null;
    }

    /**
     * Job names available under one extension.
     *
     * @return array<int, string>
     */
    public function forExtension(string $extension): array
    {
        $prefix = $extension . ':';
        $names = [];

        foreach (array_keys($this->all()) as $key) {
            if (str_starts_with($key, $prefix)) {
                $names[] = substr($key, strlen($prefix));
            }
        }

        return $names;
    }
}
