<?php

namespace Botex\Model;

use Botex\Bot\Job\JobStatus;
use Botex\Bot\Job\Schedule;
use Botex\Bot\Job\ScheduleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A scheduled piece of work.
 *
 * Deliberately has no claim/finish helpers: those are conditional UPDATEs
 * on JobRepository, because a read-then-save would let two workers run
 * the same job at once.
 */
class Job extends Model
{
    protected $table = 'jobs';

    protected $fillable = [
        'key',
        'extension',
        'job',
        'data',
        'schedule_type',
        'interval',
        'status',
        'next_run_at',
        'max_runs',
        'runs',
        'attempts',
        'max_attempts',
        'failures',
        'locked_by',
        'lease_until',
        'started_at',
        'finished_at',
        'duration_ms',
        'last_error',
    ];

    protected $casts = [
        'data' => 'array',
        'interval' => 'integer',
        'max_runs' => 'integer',
        'runs' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'failures' => 'integer',
        'duration_ms' => 'integer',
        'next_run_at' => 'datetime',
        'lease_until' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function status(): JobStatus
    {
        return JobStatus::from((string) $this->status);
    }

    public function scheduleType(): ScheduleType
    {
        return ScheduleType::from((string) $this->schedule_type);
    }

    public function repeats(): bool
    {
        return $this->scheduleType()->repeats();
    }

    public function runsForever(): bool
    {
        return $this->repeats() && (int) $this->max_runs === Schedule::FOREVER;
    }

    /** True once the job has run as many times as it was going to. */
    public function isExhausted(): bool
    {
        if ($this->runsForever()) {
            return false;
        }

        return (int) $this->runs >= (int) $this->max_runs;
    }

    /** True when a claimed job's lease has lapsed, so its worker died. */
    public function leaseExpired(): bool
    {
        return $this->lease_until !== null && $this->lease_until->isPast();
    }

    public function isDue(): bool
    {
        return $this->next_run_at !== null && !$this->next_run_at->isFuture();
    }

    /** How this job is addressed in the Jobs allowlist. */
    public function handlerKey(): string
    {
        return $this->extension . ':' . $this->job;
    }

    /**
     * Rebuilds the schedule, for working out the next run.
     *
     * A repeating row whose interval is missing or out of range is treated
     * as one-shot rather than rejected. Schedule::every() would throw, and
     * this is called after a job has already run: the exception would take
     * the worker down and leave the row claimed, so the next start re-runs
     * work that succeeded. Retiring the bad row is the safer answer.
     */
    public function schedule(): Schedule
    {
        if ($this->hasInterval()) {
            return Schedule::every((int) $this->interval, (int) $this->max_runs);
        }

        return Schedule::now();
    }

    /** A repeating row with a usable interval, as stored. */
    public function hasInterval(): bool
    {
        return $this->repeats()
            && $this->interval !== null
            && (int) $this->interval >= Schedule::MIN_INTERVAL;
    }

    /** Wording for the panel, e.g. "every 30m". */
    public function describeSchedule(): string
    {
        if (!$this->hasInterval()) {
            return ScheduleType::ONCE->label();
        }

        return 'every ' . Schedule::humanize((int) $this->interval)
            . ($this->runsForever() ? '' : ', ' . (int) $this->max_runs . 'x');
    }

    /** "in 4m", "3m ago", or a dash when nothing is scheduled. */
    public function describeNextRun(): string
    {
        if ($this->next_run_at === null) {
            return '-';
        }

        $seconds = Carbon::now()->diffInSeconds($this->next_run_at, false);

        if ($seconds >= 0) {
            return 'in ' . Schedule::humanize((int) $seconds);
        }

        return Schedule::humanize((int) abs($seconds)) . ' ago';
    }
}
