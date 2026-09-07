<?php

namespace Botex\Bot\Job;

use Illuminate\Support\Carbon;

/**
 * When a job should run, and whether it runs again afterwards.
 *
 * Built through the named constructors rather than by hand, so an
 * interval schedule can never end up without an interval.
 */
class Schedule
{
    /** An interval job that should keep going indefinitely. */
    public const FOREVER = 0;

    /** Refuses anything tighter than this, whatever the caller asks for. */
    public const MIN_INTERVAL = 1;

    private function __construct(
        public readonly ScheduleType $type,
        public readonly Carbon $firstRun,
        public readonly ?int $interval = null,
        public readonly int $maxRuns = 1
    ) {
    }

    /** Runs as soon as the worker next looks. */
    public static function now(): self
    {
        return new self(ScheduleType::ONCE, Carbon::now());
    }

    /**
     * Runs once, this many seconds from now.
     *
     * The "+30 minutes" case: in(1800).
     */
    public static function in(int $seconds): self
    {
        return new self(ScheduleType::ONCE, Carbon::now()->addSeconds(
            self::positive($seconds, 'delay')
        ));
    }

    /** Runs once, at a specific moment. */
    public static function at(\DateTimeInterface $when): self
    {
        return new self(ScheduleType::ONCE, Carbon::instance($when));
    }

    /**
     * Runs every N seconds, starting one interval from now.
     *
     * @param int $maxRuns self::FOREVER to keep going
     */
    public static function every(int $seconds, int $maxRuns = self::FOREVER): self
    {
        $seconds = self::interval($seconds);

        return new self(
            ScheduleType::INTERVAL,
            Carbon::now()->addSeconds($seconds),
            $seconds,
            max(self::FOREVER, $maxRuns)
        );
    }

    public static function everyMinutes(int $minutes, int $maxRuns = self::FOREVER): self
    {
        return self::every(self::positive($minutes, 'minutes') * 60, $maxRuns);
    }

    public static function everyHours(int $hours, int $maxRuns = self::FOREVER): self
    {
        return self::every(self::positive($hours, 'hours') * 3600, $maxRuns);
    }

    public static function daily(int $maxRuns = self::FOREVER): self
    {
        return self::everyHours(24, $maxRuns);
    }

    /** Runs the first one straight away instead of after one interval. */
    public function startingNow(): self
    {
        return new self($this->type, Carbon::now(), $this->interval, $this->maxRuns);
    }

    /** Delays the first run without changing the rhythm after it. */
    public function startingIn(int $seconds): self
    {
        return new self(
            $this->type,
            Carbon::now()->addSeconds(self::positive($seconds, 'delay')),
            $this->interval,
            $this->maxRuns
        );
    }

    public function startingAt(\DateTimeInterface $when): self
    {
        return new self($this->type, Carbon::instance($when), $this->interval, $this->maxRuns);
    }

    /** Caps how many times an interval job runs before it is done. */
    public function times(int $runs): self
    {
        return new self(
            $this->type,
            $this->firstRun,
            $this->interval,
            max(self::FOREVER, $runs)
        );
    }

    public function repeats(): bool
    {
        return $this->type->repeats();
    }

    public function runsForever(): bool
    {
        return $this->repeats() && $this->maxRuns === self::FOREVER;
    }

    /**
     * When a job on this schedule should next run, counted from when the
     * last one finished.
     *
     * Measuring from completion rather than from the due time means a job
     * that takes longer than its interval falls behind instead of
     * queueing up a backlog it can never clear.
     */
    public function nextAfter(Carbon $finishedAt): ?Carbon
    {
        if (!$this->repeats() || $this->interval === null) {
            return null;
        }

        return $finishedAt->copy()->addSeconds($this->interval);
    }

    /** Wording for the panel, e.g. "every 30m" or "one time". */
    public function describe(): string
    {
        if (!$this->repeats() || $this->interval === null) {
            return $this->type->label();
        }

        return 'every ' . self::humanize($this->interval)
            . ($this->runsForever() ? '' : ", {$this->maxRuns}x");
    }

    /** 5400 reads as "1h 30m". */
    public static function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $parts = [];

        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size) . $unit;
                $seconds %= $size;
            }
        }

        return implode(' ', $parts);
    }

    private static function interval(int $seconds): int
    {
        if ($seconds < self::MIN_INTERVAL) {
            throw new \InvalidArgumentException(
                'A repeating job needs an interval of at least '
                . self::MIN_INTERVAL . ' second.'
            );
        }

        return $seconds;
    }

    private static function positive(int $value, string $label): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("A job {$label} cannot be negative.");
        }

        return $value;
    }
}
