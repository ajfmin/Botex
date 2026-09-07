<?php

namespace Botex\Bot\Job;

use Botex\Model\Job;
use Botex\Repository\JobRepository;

/**
 * The claimed job handed to its handler.
 *
 * Everything here comes off the stored row. A handler reads its data
 * through the same dot-notation accessors RunContext offers, so moving
 * between the two feels the same.
 */
class JobContext
{
    public function __construct(
        public readonly int $id,
        public readonly string $extension,
        public readonly string $job,
        public readonly array $data,
        public readonly int $run,
        public readonly int $attempt,
        private readonly string $workerId = '',
        private readonly ?JobRepository $jobs = null
    ) {
    }

    /**
     * Built from a row that has already been claimed.
     *
     * runs counts completed runs, so this one is the next; attempts was
     * incremented by the claim, so it already names this attempt.
     */
    public static function fromRow(Job $row, string $workerId = '', ?JobRepository $jobs = null): self
    {
        return new self(
            (int) $row->id,
            (string) $row->extension,
            (string) $row->job,
            is_array($row->data) ? $row->data : [],
            (int) $row->runs + 1,
            max(1, (int) $row->attempts),
            $workerId,
            $jobs
        );
    }

    /** Reads a value out of the job data, dot-notated for nesting. */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $sentinel = new \stdClass()) !== $sentinel;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : (bool) $value;
    }

    /** True on the job's first ever run. */
    public function isFirstRun(): bool
    {
        return $this->run <= 1;
    }

    /** True when an earlier attempt at this run already failed. */
    public function isRetry(): bool
    {
        return $this->attempt > 1;
    }

    /**
     * Pushes the lease out, for a handler that legitimately runs long.
     *
     * Without this, work that outlasts the lease looks abandoned and gets
     * reclaimed. Call it periodically from anything slow.
     *
     * @return bool false when this worker has already lost the job, so a
     *              long-running handler can notice and stop early
     */
    public function heartbeat(int $seconds = 60): bool
    {
        if ($this->jobs === null || $this->workerId === '') {
            return true;
        }

        return $this->jobs->touchLease($this->id, $this->workerId, $seconds);
    }

    /** How this job is addressed in the Jobs allowlist. */
    public function handlerKey(): string
    {
        return $this->extension . ':' . $this->job;
    }
}
