<?php

namespace Botex\Bot\Job;

use Botex\Bot\Feeder;
use Botex\Repository\JobRepository;
use Botex\Service\JobService;
use Botex\Support\Config;
use Botex\Support\Log\Level;
use Botex\Support\Log\Logger;
use Illuminate\Support\Carbon;

/**
 * The single long-running service.
 *
 * One process, one loop: take the worker lease, repeatedly ask for due
 * jobs, claim them, run them, re-arm them. Everything the bot wants done
 * "later" happens here, so nothing in the webhook path ever sleeps.
 *
 * The loop deliberately does no clock arithmetic of its own. Whether a job
 * is due is a WHERE clause, and whether this process may run it is the
 * affected-row count from JobRepository::claim().
 */
class Worker
{
    /** Seconds between polls when there was nothing to do. */
    public const DEFAULT_SLEEP = 5;

    /** How long a claim is good for before it counts as abandoned. */
    public const DEFAULT_LEASE = 300;

    /** Jobs taken per poll, so one busy schedule cannot starve the rest. */
    public const BATCH = 25;

    /**
     * How long a job whose handler is not loadable waits before trying
     * again. Long enough not to be a retry loop, short enough that
     * installing the extension and restarting the worker is the whole
     * fix rather than the start of one.
     */
    public const MISSING_HANDLER_GRACE = 300;

    private bool $stopping = false;

    private string $workerId = '';

    private int $ran = 0;

    /** True once run() holds the lease, so tick() knows to keep it alive. */
    private bool $holdsLease = false;

    /** Set when the lease was taken away, to unwind out of a batch. */
    private bool $lostLease = false;

    /** Jobs run since the last lease renewal, so none are counted twice. */
    private int $unreported = 0;

    /** @var callable|null console sink, set by whoever started the worker */
    private $echo = null;

    public function __construct(
        private JobRepository $jobs,
        private JobService $service,
        private Jobs $handlers,
        private WorkerLease $lease,
        private Feeder $feeder,
        private Config $config,
        private Logger $log
    ) {
    }

    /** Where progress lines go; the console command prints them. */
    public function onLog(callable $logger): void
    {
        $this->echo = $logger;
    }

    /**
     * Runs until stopped.
     *
     * @param  int  $maxSeconds 0 runs forever; a limit is what makes this
     *                          testable and lets cron-style supervision
     *                          restart it periodically
     * @return int jobs run
     */
    public function run(int $maxSeconds = 0): int
    {
        $this->ensureWorkerId();

        if (!$this->lease->acquire($this->workerId, $this->leaseSeconds())) {
            // Not a failure, but worth noticing: it means a second worker
            // was started while one was alive.
            $this->log(
                'Another worker holds the lease: ' . $this->lease->describe(),
                Level::Notice
            );

            return 0;
        }

        $this->listenForSignals();
        $this->log("Worker {$this->workerId} started.", context: ['worker' => $this->workerId]);

        $startedAt = Carbon::now();
        $this->ran = 0;
        $this->holdsLease = true;
        $this->lostLease = false;
        $this->unreported = 0;

        try {
            while (!$this->stopping) {
                $worked = $this->tick();

                    if ($this->lostLease || !$this->keepLease()) {
                    // Someone else owns the system now. Stop rather than
                    // run a second copy of every schedule.
                    $this->log('Lost the worker lease; stopping.', Level::Warning);
                    break;
                }

                if ($maxSeconds > 0 && $startedAt->diffInSeconds(Carbon::now()) >= $maxSeconds) {
                    break;
                }

                if ($worked === 0) {
                    $this->rest();
                }
            }
        } finally {
            $this->holdsLease = false;
            $this->lease->release($this->workerId);
            $this->log(
                "Worker {$this->workerId} stopped after {$this->ran} job(s).",
                context: ['worker' => $this->workerId, 'ran' => $this->ran]
            );
        }

        return $this->ran;
    }

    /**
     * One poll: claim and run everything currently due.
     *
     * @return int jobs run this pass
     */
    public function tick(): int
    {
        // tick() is also the --once entry point, which never went through
        // run(). Without an id here, ownership could not be proved and a
        // handler's heartbeat() would quietly do nothing.
        $this->ensureWorkerId();

        $worked = 0;

        foreach ($this->jobs->dueIds(self::BATCH) as $id) {
            if ($this->stopping) {
                break;
            }

            if ($this->runJob($id)) {
                $worked++;
                $this->unreported++;
            }

            // A batch of slow jobs can easily outlast the lease, and the
            // renewal between polls is too late: another worker would have
            // started the whole schedule again while this one was still
            // working through the batch. So the lease is kept alive per
            // job, and losing it abandons the rest of the batch.
            if (!$this->keepLease()) {
                $this->lostLease = true;
                break;
            }
        }

        $this->ran += $worked;

        return $worked;
    }

    /**
     * Claims one job and runs it.
     *
     * @return bool false when another worker got there first, or the job
     *              was not runnable
     */
    public function runJob(int $id): bool
    {
        $this->ensureWorkerId();

        $job = $this->jobs->claim($id, $this->workerId, $this->leaseSeconds());

        if ($job === null) {
            // Lost the race, or it stopped being due. Either way not ours.
            return false;
        }

        $class = $this->handlers->findByKey($job->handlerKey());

        if ($class === null) {
            // The handler was renamed, or its extension is disabled, not
            // yet installed, or -- most often -- simply not in this
            // worker's map, because the process was already running when
            // the extension arrived. The row is not broken; it has nothing
            // to run *right now*.
            //
            // So it is deferred rather than paused. Pausing kept it out of
            // the tight retry loop, which was the point, but nothing ever
            // brought it back: restarting the worker did not, re-enabling
            // the extension did not, and re-arming found a row already
            // there and left it alone. A job that needs a human to notice
            // it is the wrong default for the commonest cause of this.
            //
            // Deferring costs one query per grace period. A row whose
            // extension is really gone is deleted by ext:remove.
            $this->jobs->setStatus(
                (int) $job->id,
                JobStatus::PENDING,
                Carbon::now()->addSeconds(self::MISSING_HANDLER_GRACE)
            );
            $this->log(
                "Job #{$job->id} ({$job->handlerKey()}) has no handler here; retrying in "
                    . Schedule::humanize(self::MISSING_HANDLER_GRACE)
                    . '. Is its extension installed and enabled, and has this worker been'
                    . ' restarted since?',
                Level::Warning,
                $this->about($job)
            );

            return false;
        }

        $context = JobContext::fromRow($job, $this->workerId, $this->jobs);
        $startedAt = microtime(true);

        try {
            $handler = $this->feeder->make($class);

            if (!$handler instanceof JobInterface) {
                throw new \RuntimeException("{$class} must implement JobInterface.");
            }

            $handler->handle($context);
        } catch (\Throwable $e) {
            $this->record(fn () => $this->failed($job, $e), $job);

            return true;
        }

        $this->record(
            fn () => $this->finished($job, (int) round((microtime(true) - $startedAt) * 1000)),
            $job
        );

        return true;
    }

    /**
     * Writes a run's outcome, refusing to let that write kill the worker.
     *
     * The handler has already done its work by this point. An exception
     * escaping here used to take the whole process down and leave the row
     * claimed, so the next start re-ran a job that had in fact succeeded,
     * over and over. Pausing instead makes it one visible bad row.
     */
    private function record(callable $write, \Botex\Model\Job $job): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            // The message repeats what the console needs to see: file
            // context is invisible to whoever is tailing the process.
            $this->log(
                "Job #{$job->id} ({$job->handlerKey()}) ran, but recording the result"
                    . ' failed: ' . $e->getMessage() . '; paused.',
                Level::Error,
                $this->about($job),
                $e
            );

            try {
                $this->jobs->setStatus((int) $job->id, JobStatus::PAUSED);
            } catch (\Throwable) {
                // Nothing left to try. The lease lapses and another worker
                // may pick it up, which is the old behaviour anyway.
            }
        }
    }

    /**
     * Extends the worker lease, reporting jobs run since the last one.
     *
     * True when there is no lease to keep, so a --once tick() is unaffected.
     */
    private function keepLease(): bool
    {
        if (!$this->holdsLease) {
            return true;
        }

        $ran = $this->unreported;
        $this->unreported = 0;

        if ($this->lease->renew($this->workerId, $this->leaseSeconds(), $ran)) {
            return true;
        }

        $this->lostLease = true;

        return false;
    }

    /** Asks the loop to finish the current job and stop. */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    /** Re-arms a repeating job, or retires it. */
    private function finished(\Botex\Model\Job $job, int $durationMs): void
    {
        $nextRun = null;

        // runs is the count before this one, so add it in to decide
        // whether the job has now done everything it was asked for.
        $completed = (int) $job->runs + 1;
        $exhausted = !$job->runsForever() && $completed >= (int) $job->max_runs;

        if ($job->repeats() && !$exhausted) {
            $nextRun = $job->schedule()->nextAfter(Carbon::now());
        }

        if (!$this->jobs->complete((int) $job->id, $nextRun, $durationMs, $this->workerId)) {
            $this->lostJob($job, 'succeeded');

            return;
        }

        $this->log("Job #{$job->id} ({$job->handlerKey()}) ok in {$durationMs}ms"
            . ($nextRun ? ', next ' . $nextRun->toDateTimeString() : ', done'), context: $this->about($job) + [
                'duration_ms' => $durationMs,
            ]);
    }

    /** Records the failure and lets JobService decide about a retry. */
    private function failed(\Botex\Model\Job $job, \Throwable $e): void
    {
        $retryAt = $this->service->retryAt($job);

        if (!$this->jobs->fail((int) $job->id, $e->getMessage(), $retryAt, $this->workerId)) {
            $this->lostJob($job, 'failed with: ' . $e->getMessage());

            return;
        }

        $this->log("Job #{$job->id} ({$job->handlerKey()}) failed: " . $e->getMessage()
            . ($retryAt ? ', retry ' . $retryAt->toDateTimeString() : ', giving up'),
            Level::Error,
            $this->about($job) + ['retry_at' => $retryAt?->toDateTimeString()],
            $e
        );
    }

    /**
     * The run finished but the row had already been taken away.
     *
     * Deliberately writes nothing: the new owner's bookkeeping is the one
     * that counts, and touching the row here would either double the run
     * count or unlock a job somebody else is still running. Logged loudly
     * because it means the lease is shorter than this job really needs.
     */
    private function lostJob(\Botex\Model\Job $job, string $outcome): void
    {
        // Warning rather than error: this worker did its work, but a
        // duplicate of it ran somewhere else, which is exactly what the
        // lease exists to prevent.
        $this->log(
            "Job #{$job->id} ({$job->handlerKey()}) {$outcome}, but its lease had"
                . ' lapsed and another worker owns it; result discarded. Raise jobs.lease'
                . ' or call heartbeat() from this handler.',
            Level::Warning,
            $this->about($job)
        );
    }

    private function rest(): void
    {
        $seconds = max(1, (int) $this->config->get('jobs.sleep', self::DEFAULT_SLEEP));

        // Slept in one-second steps so a stop signal is noticed promptly
        // rather than after the whole interval.
        for ($i = 0; $i < $seconds && !$this->stopping; $i++) {
            sleep(1);
        }
    }

    private function leaseSeconds(): int
    {
        return max(5, (int) $this->config->get('jobs.lease', self::DEFAULT_LEASE));
    }

    /**
     * Identifies this process for the lease and for job ownership.
     *
     * Assigned once and kept, because it is what proves a claim belongs to
     * this worker: a fresh id per job would make renewing a lease
     * impossible.
     */
    private function ensureWorkerId(): void
    {
        if ($this->workerId === '') {
            $this->workerId = substr(bin2hex(random_bytes(6)), 0, 12);
        }
    }

    /** Lets Ctrl-C and a supervisor's TERM finish cleanly, where available. */
    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, fn () => $this->stop());
        }
    }

    /**
     * One line, two places: the console sink for whoever is watching the
     * process, and the log file, which is also what reaches the admins at
     * serious levels. Everything the worker says carries type=job.
     */
    private function log(
        string $message,
        Level $level = Level::Info,
        array $context = [],
        ?\Throwable $e = null
    ): void {
        if ($this->echo !== null) {
            ($this->echo)($message);
        }

        $context += ['type' => 'job'];

        if ($e !== null) {
            $this->log->exception($e, $message, $level, $context);

            return;
        }

        $this->log->log($level, $message, $context);
    }

    /** The context that says which job a line is about. */
    private function about(\Botex\Model\Job $job): array
    {
        return [
            'extension' => (string) $job->extension,
            'job' => (string) $job->job,
            'job_id' => (int) $job->id,
        ];
    }
}
