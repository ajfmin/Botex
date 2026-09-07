<?php

namespace Botex\Repository;

use Botex\Bot\Job\JobStatus;
use Botex\Model\Job;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * Row access for the jobs table.
 *
 * The mutating methods are single conditional statements. A job that runs
 * twice is this system's whole failure mode, so the guard that decides
 * whether a run may happen lives in the WHERE clause and the answer is
 * read from the affected-row count, never from a value fetched earlier.
 */
class JobRepository
{
    public function find(int $id): ?Job
    {
        return Job::find($id);
    }

    public function findByKey(string $key): ?Job
    {
        return Job::where('key', $key)->first();
    }

    /**
     * Ids of jobs that are due and unclaimed, oldest first.
     *
     * Includes jobs whose lease has lapsed: that worker died mid-run, so
     * the work is owned by nobody and may be picked up again.
     */
    public function dueIds(int $limit = 25): array
    {
        $now = Carbon::now();

        return Job::query()
            ->whereIn('status', [JobStatus::PENDING->value, JobStatus::RUNNING->value])
            ->where('next_run_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('lease_until')->orWhere('lease_until', '<=', $now);
            })
            ->orderBy('next_run_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Takes exclusive ownership of a due job, or returns null.
     *
     * Everything that must hold for a run to be legal is in the WHERE:
     * still due, and either unclaimed or abandoned by a dead worker. Two
     * workers racing the same id both issue this UPDATE; exactly one
     * reports a row changed, and only that one gets the job back.
     */
    public function claim(int $id, string $workerId, int $leaseSeconds): ?Job
    {
        $now = Carbon::now();

        $claimed = Job::query()
            ->where('id', $id)
            ->whereIn('status', [JobStatus::PENDING->value, JobStatus::RUNNING->value])
            ->where('next_run_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('lease_until')->orWhere('lease_until', '<=', $now);
            })
            ->update([
                'status' => JobStatus::RUNNING->value,
                'locked_by' => $workerId,
                'lease_until' => $now->copy()->addSeconds(max(1, $leaseSeconds)),
                'started_at' => $now,
                'attempts' => new Expression('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $this->find($id) : null;
    }

    /**
     * Extends the lease of a job this worker still holds.
     *
     * The locked_by guard stops a worker that already lost the job (its
     * lease lapsed and another worker reclaimed it) from stealing it back.
     *
     * @return bool false when the caller no longer owns the job
     */
    public function touchLease(int $id, string $workerId, int $seconds): bool
    {
        $updated = Job::query()
            ->where('id', $id)
            ->where('locked_by', $workerId)
            ->update([
                'lease_until' => Carbon::now()->addSeconds(max(1, $seconds)),
            ]);

        if ($updated === 1) {
            return true;
        }

        // A heartbeat landing in the same second as the last one writes an
        // identical lease_until. Without MYSQL_ATTR_FOUND_ROWS that reports
        // 0 rows and a handler would think it had lost a job it still
        // holds, so ownership is confirmed directly before saying no.
        return Job::query()
            ->where('id', $id)
            ->where('locked_by', $workerId)
            ->exists();
    }

    /**
     * Narrows to a job this worker still holds.
     *
     * An empty worker id means the caller is not claiming ownership (the
     * console re-scheduling a row by hand), so only the id is matched.
     */
    private function owned(int $id, string $workerId): \Illuminate\Database\Eloquent\Builder
    {
        $query = Job::query()->where('id', $id);

        return $workerId === '' ? $query : $query->where('locked_by', $workerId);
    }

    /**
     * Records a completed run and re-arms or retires the job.
     *
     * Guarded by locked_by for the same reason touchLease() is: a worker
     * whose lease lapsed mid-run has already been replaced, and writing
     * the outcome anyway would count the run twice and hand the row back
     * while the new owner is still using it.
     *
     * @return bool false when the caller no longer owns the job
     */
    public function complete(int $id, ?Carbon $nextRun, int $durationMs, string $workerId = ''): bool
    {
        $now = Carbon::now();

        return $this->owned($id, $workerId)
            ->update([
                'status' => $nextRun ? JobStatus::PENDING->value : JobStatus::DONE->value,
                'runs' => new Expression('runs + 1'),
                'attempts' => 0,
                'next_run_at' => $nextRun,
                'locked_by' => null,
                'lease_until' => null,
                'finished_at' => $now,
                'duration_ms' => $durationMs,
                'last_error' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Records a failed attempt, scheduling a retry or giving up.
     *
     * Ownership-guarded like complete(): a lapsed worker reporting its
     * failure must not overwrite the retry schedule of the run that
     * replaced it.
     *
     * @return bool false when the caller no longer owns the job
     */
    public function fail(int $id, string $error, ?Carbon $retryAt, string $workerId = ''): bool
    {
        $now = Carbon::now();

        return $this->owned($id, $workerId)
            ->update([
                'status' => $retryAt ? JobStatus::PENDING->value : JobStatus::FAILED->value,
                'failures' => new Expression('failures + 1'),
                'next_run_at' => $retryAt,
                'locked_by' => null,
                'lease_until' => null,
                'finished_at' => $now,
                // Truncated: an exception message can be arbitrarily long.
                'last_error' => mb_substr($error, 0, 500),
                'updated_at' => $now,
            ]) === 1;
    }

    public function setStatus(int $id, JobStatus $status, ?Carbon $nextRun = null): bool
    {
        return Job::query()
            ->where('id', $id)
            ->update([
                'status' => $status->value,
                'next_run_at' => $nextRun,
                'locked_by' => null,
                'lease_until' => null,
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    public function delete(int $id): bool
    {
        return Job::where('id', $id)->delete() === 1;
    }

    public function forgetExtension(string $extension): int
    {
        return Job::where('extension', $extension)->delete();
    }

    /** @return Collection<int, Job> */
    public function recent(int $limit = 50): Collection
    {
        return Job::query()
            ->orderByRaw('CASE WHEN next_run_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_run_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function count(): int
    {
        return Job::count();
    }

    public function countByStatus(JobStatus $status): int
    {
        return Job::where('status', $status->value)->count();
    }

    public function countDue(): int
    {
        return Job::query()
            ->whereIn('status', [JobStatus::PENDING->value, JobStatus::RUNNING->value])
            ->where('next_run_at', '<=', Carbon::now())
            ->count();
    }

    /** Removes finished jobs that are no longer interesting. */
    public function prune(int $keepSeconds): int
    {
        return Job::query()
            ->whereIn('status', [JobStatus::DONE->value, JobStatus::FAILED->value])
            ->where('finished_at', '<', Carbon::now()->subSeconds(max(0, $keepSeconds)))
            ->delete();
    }
}
