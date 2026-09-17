<?php

namespace Botex\Broadcast;

use Botex\Bot\Job\JobRequest;
use Botex\Bot\Job\SendBroadcast;
use Botex\Bot\Job\Schedule;
use Botex\Model\Broadcast;
use Botex\Repository\BroadcastRepository;
use Botex\Repository\UserRepository;
use Botex\Service\JobService;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;
use Illuminate\Support\Carbon;

/**
 * The broadcast API: compose one, control it, read how it went.
 *
 * Nothing here sends a message to a user. Queueing returns the moment
 * one row is written, exactly as JobService does, because the thing
 * being asked for takes minutes or hours and the person asking is
 * holding a phone. The worker does the work; this decides what the work
 * is and what may be done to it while it runs.
 *
 * One broadcast at a time, enforced here rather than by the worker: two
 * running at once would each pace themselves to the configured rate and
 * together send at twice it, which is the one mistake this whole
 * subsystem exists to avoid.
 */
class BroadcastService
{
    /** The standing runner job, keyed so there is only ever one. */
    public const JOB_KEY = 'core:broadcast';

    /** The job name inside the core slug. */
    public const JOB = 'broadcast';

    public function __construct(
        private BroadcastRepository $broadcasts,
        private UserRepository $users,
        private JobService $jobs,
        private Sender $sender,
        private Bot $bot,
        private Logger $log
    ) {
    }

    /** How many people an announcement would reach right now. */
    public function audience(): int
    {
        return $this->users->countReachable();
    }

    /** Messages a second this install sends at. */
    public function rate(): int
    {
        return $this->sender->rate();
    }

    /** Seconds the whole audience would take at the configured rate. */
    public function estimate(): int
    {
        return $this->sender->estimate($this->audience());
    }

    /** Seconds a given number of recipients would take. */
    public function estimateFor(int $recipients): int
    {
        return $this->sender->estimate($recipients);
    }

    /** People left out of every broadcast because delivery has failed. */
    public function unreachable(): int
    {
        return $this->users->countUnreachable();
    }

    /**
     * A paused broadcast, when nothing is actually going out.
     *
     * The panel shows one so that resuming is reachable. Without it a
     * paused send would vanish from the screen that paused it, which is
     * the shortest route to an announcement that never finishes and
     * nobody remembers stopping.
     */
    public function held(): ?Broadcast
    {
        foreach ($this->broadcasts->unfinished() as $broadcast) {
            if ($broadcast->status() === Status::PAUSED) {
                return $broadcast;
            }
        }

        return null;
    }

    /** The one being delivered right now, if any. */
    public function running(): ?Broadcast
    {
        return $this->broadcasts->active();
    }

    /** @return array<Broadcast> newest first */
    public function recent(int $limit = 5): array
    {
        return $this->broadcasts->recent($limit);
    }

    public function find(int $id): ?Broadcast
    {
        return $this->broadcasts->find($id);
    }

    /**
     * Queues a copy of a message the bot can see.
     *
     * @throws BroadcastException when one is already going out
     */
    public function queueCopy(int $createdBy, int $fromChatId, int $messageId, bool $silent = false): Broadcast
    {
        return $this->queue([
            'created_by' => $createdBy,
            'kind' => Broadcast::COPY,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
            'silent' => $silent,
        ]);
    }

    /**
     * Queues a body composed somewhere with no chat to copy from.
     *
     * @throws BroadcastException when one is already going out
     */
    public function queueText(int $createdBy, string $body, string $parseMode = 'HTML', bool $silent = false): Broadcast
    {
        if (trim($body) === '') {
            throw new BroadcastException('A broadcast needs something to say.');
        }

        return $this->queue([
            'created_by' => $createdBy,
            'kind' => Broadcast::TEXT,
            'body' => $body,
            'parse_mode' => $parseMode,
            'silent' => $silent,
        ]);
    }

    /**
     * Marks a queued broadcast as under way and counts its audience.
     *
     * The count happens here, not at compose time, because an admin who
     * writes an announcement and sends it an hour later should reach the
     * people who are there when it goes out. It is also the number every
     * progress figure is measured against, so it is written once and not
     * revised.
     */
    public function begin(Broadcast $broadcast): bool
    {
        return $this->broadcasts->transition(
            (int) $broadcast->id,
            Status::SENDING,
            [Status::QUEUED],
            [
                'total' => $this->audience(),
                'started_at' => Carbon::now(),
            ]
        );
    }

    /** Holds a running broadcast where it is. The cursor is kept. */
    public function pause(int $id): bool
    {
        return $this->broadcasts->transition(
            $id,
            Status::PAUSED,
            [Status::QUEUED, Status::SENDING]
        );
    }

    /** Puts a paused one back in the queue, from where it stopped. */
    public function resume(int $id): bool
    {
        $moved = $this->broadcasts->transition($id, Status::SENDING, [Status::PAUSED]);

        if ($moved) {
            $this->arm();
        }

        return $moved;
    }

    /** Stops one for good. What was already delivered stays delivered. */
    public function cancel(int $id): bool
    {
        return $this->broadcasts->transition(
            $id,
            Status::CANCELLED,
            [Status::QUEUED, Status::SENDING, Status::PAUSED],
            ['finished_at' => Carbon::now()]
        );
    }

    /**
     * Closes a finished broadcast and tells whoever started it.
     *
     * The report goes to the admin rather than only into the panel
     * because a broadcast outlives the screen it was started from: by
     * the time a hundred thousand messages have gone out, nobody is
     * still looking at the message that launched them.
     */
    public function finish(Broadcast $broadcast): void
    {
        $id = (int) $broadcast->id;

        // Read back rather than trusted: the counters moved while the
        // last slice ran, and the difference between what the audience
        // was and what was actually tried is the number being written
        // here. Those are people who left the audience mid-send -- an
        // admin blocked them, or an earlier message in this same
        // broadcast found them unreachable.
        $latest = $this->broadcasts->find($id) ?? $broadcast;
        $skipped = max(0, (int) $latest->total - $latest->attempted());

        $done = $this->broadcasts->transition(
            $id,
            Status::DONE,
            [Status::SENDING, Status::QUEUED],
            [
                'skipped' => $skipped,
                'finished_at' => Carbon::now(),
            ]
        );

        if (!$done) {
            return;
        }

        $final = $this->broadcasts->find($id) ?? $latest;

        $this->log->info('Broadcast finished', [
            'broadcast' => $id,
            'sent' => (int) $final->sent,
            'blocked' => (int) $final->blocked,
            'gone' => (int) $final->gone,
            'failed' => (int) $final->failed,
            'skipped' => (int) $final->skipped,
        ]);

        $this->notify($final);
    }

    /** Sends the report to the admin who composed it. */
    public function notify(Broadcast $broadcast): void
    {
        if ((int) $broadcast->created_by < 1) {
            return;
        }

        $this->bot->sendMessage(implode(PHP_EOL, $this->report($broadcast)))
            ->to((int) $broadcast->created_by)
            ->parseMode('HTML')
            ->execute();
    }

    /**
     * The report, as HTML lines.
     *
     * Every number is here, including the ones an admin would rather not
     * see. A broadcast that reports only "1,284 sent" is worse than
     * useless: it is the number that makes a bot look healthy while its
     * real reach halves.
     *
     * @return array<string>
     */
    public function report(Broadcast $broadcast): array
    {
        $status = $broadcast->status();

        $lines = [
            $status->icon() . ' <b>Broadcast #' . (int) $broadcast->id . '</b> - '
                . $this->escape($status->label()),
            '',
            '✅ Delivered: <b>' . number_format((int) $broadcast->sent) . '</b>',
            '🚫 Blocked the bot: ' . number_format((int) $broadcast->blocked),
            '👻 Account gone: ' . number_format((int) $broadcast->gone),
            '⚠️ Failed: ' . number_format((int) $broadcast->failed),
        ];

        if ((int) $broadcast->skipped > 0) {
            $lines[] = '⏭ Skipped: ' . number_format((int) $broadcast->skipped);
        }

        $lines[] = '';
        $lines[] = sprintf(
            '%s of %s accounted for (%d%%)',
            number_format($broadcast->accounted()),
            number_format((int) $broadcast->total),
            $broadcast->percent()
        );

        if ($broadcast->started_at && $broadcast->finished_at) {
            $lines[] = 'Took ' . $this->duration(
                (int) $broadcast->finished_at->diffInSeconds($broadcast->started_at, true)
            );
        }

        if ($broadcast->last_error) {
            $lines[] = '';
            $lines[] = '<i>Last error: ' . $this->escape(
                mb_substr((string) $broadcast->last_error, 0, 160)
            ) . '</i>';
        }

        return $lines;
    }

    /** e.g. "2m 11s" */
    public function duration(int $seconds): string
    {
        return Schedule::humanize(max(0, $seconds));
    }

    /**
     * Writes the row and arms the job that will deliver it.
     *
     * @param array<string,mixed> $attributes
     *
     * @throws BroadcastException
     */
    private function queue(array $attributes): Broadcast
    {
        // Anything unfinished blocks a new one, paused included. Two
        // sending at once would each pace themselves to the configured
        // rate and together send at twice it, which is the mistake this
        // whole subsystem exists to avoid -- and a paused one that is
        // quietly pushed off the screen by a newer broadcast is how an
        // announcement ends up half delivered with nobody remembering.
        foreach ($this->broadcasts->unfinished() as $existing) {
            throw new BroadcastException(sprintf(
                'Broadcast #%d is %s. Finish, resume or stop it first.',
                (int) $existing->id,
                strtolower($existing->status()->label())
            ));
        }

        if ($this->audience() < 1) {
            throw new BroadcastException('There is nobody to send to yet.');
        }

        $broadcast = $this->broadcasts->create($attributes + [
            'status' => Status::QUEUED->value,
            'cursor' => 0,
        ]);

        $this->arm();

        $this->log->info('Broadcast queued', [
            'broadcast' => (int) $broadcast->id,
            'by' => (int) $broadcast->created_by,
            'audience' => $this->audience(),
        ]);

        return $broadcast;
    }

    /**
     * Makes sure the runner job exists.
     *
     * A standing job rather than one per broadcast; see
     * Botex\Bot\Job\SendBroadcast for why. ensure() is idempotent, so
     * calling it on every queue and resume costs one lookup and fixes
     * the case that otherwise strands an announcement forever -- a bot
     * whose worker was started before this feature existed, and whose
     * admin has no reason to know the difference.
     */
    public function arm(): void
    {
        $this->jobs->ensure(
            JobRequest::to(
                JobRequest::CORE,
                self::JOB,
                Schedule::every(SendBroadcast::INTERVAL)->startingNow()
            )->keyed(self::JOB_KEY)
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
