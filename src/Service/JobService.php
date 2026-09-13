<?php

namespace Botex\Service;

use Botex\Bot\Job\JobRequest;
use Botex\Bot\Job\JobStatus;
use Botex\Bot\Job\Jobs;
use Botex\Bot\Job\Schedule;
use Botex\Bot\Job\UnknownJob;
use Botex\Model\Job;
use Botex\Repository\JobRepository;
use Botex\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The scheduling API. Commands, callbacks and extensions use this and
 * nothing else.
 *
 * Every method here returns as soon as one row is written. Nothing in the
 * request path ever waits for work to happen, which is the point of the
 * feature: no sleep(), no long-running webhook. The worker picks the row
 * up on its own time.
 */
class JobService
{
    /** Retry backoff in seconds, indexed by how many attempts have failed. */
    private const BACKOFF = [30, 120, 600, 1800];

    public function __construct(
        private JobRepository $jobs,
        private Jobs $handlers,
        private Config $config
    ) {
    }

    /**
     * Queues a job, or re-arms the existing one when the request is keyed.
     *
     * @throws UnknownJob when no handler answers to that name
     */
    public function schedule(JobRequest $request): Job
    {
        if (!$this->handlers->exists($request->extension, $request->job)) {
            throw UnknownJob::for($request->extension, $request->job);
        }

        $attributes = [
            'key' => $request->key,
            'extension' => $request->extension,
            'job' => $request->job,
            'data' => $request->data,
            'schedule_type' => $request->schedule->type->value,
            'interval' => $request->schedule->interval,
            'status' => JobStatus::PENDING->value,
            'next_run_at' => $request->schedule->firstRun,
            'max_runs' => $request->schedule->maxRuns,
            'runs' => 0,
            'attempts' => 0,
            'max_attempts' => $request->maxAttempts ?? $this->maxAttempts(),
            'failures' => 0,
            'locked_by' => null,
            'lease_until' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'last_error' => null,
        ];

        if ($request->key === null) {
            return Job::create($attributes);
        }

        return $this->upsert($request->key, $attributes);
    }

    /** Runs a job once, as soon as the worker next looks. */
    public function now(string $extension, string $job, array $data = []): Job
    {
        return $this->schedule(JobRequest::to($extension, $job, Schedule::now(), $data));
    }

    /**
     * Runs a job once, after a delay. The "+30 minutes" case.
     */
    public function in(string $extension, string $job, int $seconds, array $data = []): Job
    {
        return $this->schedule(JobRequest::to($extension, $job, Schedule::in($seconds), $data));
    }

    /** Runs a job once at a given moment. */
    public function at(string $extension, string $job, \DateTimeInterface $when, array $data = []): Job
    {
        return $this->schedule(JobRequest::to($extension, $job, Schedule::at($when), $data));
    }

    /**
     * Repeats a job every N seconds, forever by default.
     *
     * Recurring jobs are almost always keyed, so restarting the bot
     * re-arms the one row instead of stacking up duplicates.
     */
    public function every(
        string $extension,
        string $job,
        int $seconds,
        array $data = [],
        ?string $key = null,
        int $maxRuns = Schedule::FOREVER
    ): Job {
        $request = JobRequest::to($extension, $job, Schedule::every($seconds, $maxRuns), $data);

        return $this->schedule($key === null ? $request : $request->keyed($key));
    }

    /**
     * Schedules a job unless an equivalent one is already going to run.
     *
     * For recurring jobs registered at boot: the first call arms them, and
     * later calls leave the existing timing alone.
     *
     * "Already there" means *active* -- pending or running -- not merely
     * present. A paused row is a row the worker will never look at again,
     * and it is exactly what a job whose extension was briefly invisible
     * leaves behind; returning it here meant re-arming quietly did
     * nothing, and the thing stayed dead however many times an admin
     * pressed the button. Done and failed rows are re-armed for the same
     * reason.
     */
    public function ensure(JobRequest $request): Job
    {
        if ($request->key === null) {
            throw new \InvalidArgumentException('ensure() needs a keyed request.');
        }

        $existing = $this->jobs->findByKey($request->key);

        if ($existing && $existing->status()->isActive()) {
            return $existing;
        }

        return $this->schedule($request);
    }

    public function find(int $id): ?Job
    {
        return $this->jobs->find($id);
    }

    public function findByKey(string $key): ?Job
    {
        return $this->jobs->findByKey($key);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Job> */
    public function recent(int $limit = 50)
    {
        return $this->jobs->recent($limit);
    }

    /** Brings a job's next run forward to now. */
    public function runNow(int $id): bool
    {
        $job = $this->jobs->find($id);

        if (!$job || $job->status() === JobStatus::RUNNING) {
            return false;
        }

        return $this->jobs->setStatus($id, JobStatus::PENDING, Carbon::now());
    }

    /**
     * Stops a job running, keeping the row.
     *
     * Clearing next_run_at is what actually holds it: the worker only ever
     * selects rows that are due, so a paused job is invisible to it.
     */
    public function pause(int $id): bool
    {
        return $this->jobs->setStatus($id, JobStatus::PAUSED);
    }

    /** Puts a paused or failed job back in the queue. */
    public function resume(int $id): bool
    {
        $job = $this->jobs->find($id);

        if (!$job || !$job->status()->isResumable()) {
            return false;
        }

        return $this->jobs->setStatus($id, JobStatus::PENDING, Carbon::now());
    }

    public function cancel(int $id): bool
    {
        return $this->jobs->delete($id);
    }

    /** Called when an extension is removed, so its jobs go with it. */
    public function forgetExtension(string $extension): int
    {
        return $this->jobs->forgetExtension($extension);
    }

    /**
     * When a failed attempt should be retried, or null to give up.
     *
     * Backoff grows so a job failing on something broken upstream is not
     * hammering it every few seconds.
     */
    public function retryAt(Job $job): ?Carbon
    {
        $attempts = (int) $job->attempts;

        if ($attempts >= (int) $job->max_attempts) {
            return null;
        }

        $index = min($attempts - 1, count(self::BACKOFF) - 1);
        $delay = self::BACKOFF[max(0, $index)];

        return Carbon::now()->addSeconds($delay);
    }

    /** Counts for the panel. */
    public function stats(): array
    {
        return [
            'total' => $this->jobs->count(),
            'pending' => $this->jobs->countByStatus(JobStatus::PENDING),
            'running' => $this->jobs->countByStatus(JobStatus::RUNNING),
            'done' => $this->jobs->countByStatus(JobStatus::DONE),
            'failed' => $this->jobs->countByStatus(JobStatus::FAILED),
            'paused' => $this->jobs->countByStatus(JobStatus::PAUSED),
            'due' => $this->jobs->countDue(),
        ];
    }

    /** @return array<string, class-string> */
    public function registered(): array
    {
        return $this->handlers->all();
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('jobs.max_attempts', 3));
    }

    /**
     * Writes a keyed job, tolerating a concurrent writer.
     *
     * Two requests scheduling the same key at once would both see no row,
     * so the unique index decides and the loser updates instead.
     */
    private function upsert(string $key, array $attributes): Job
    {
        $existing = $this->jobs->findByKey($key);

        if ($existing) {
            $existing->fill($attributes)->save();

            return $existing;
        }

        try {
            return Job::create($attributes);
        } catch (QueryException $e) {
            $existing = $this->jobs->findByKey($key);

            if (!$existing) {
                throw $e;
            }

            $existing->fill($attributes)->save();

            return $existing;
        }
    }
}
