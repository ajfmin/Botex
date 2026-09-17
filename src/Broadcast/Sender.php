<?php

namespace Botex\Broadcast;

use Botex\Model\Broadcast;
use Botex\Repository\BroadcastRepository;
use Botex\Repository\UserRepository;
use Botex\Support\Config;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;

/**
 * Delivers one bounded slice of a broadcast, at a pace Telegram accepts.
 *
 * **The rate limit is the whole design.** Telegram will take about 30
 * messages a second from a bot before it starts answering 429, and a
 * broadcast that trips that does not merely slow down -- the flood wait
 * applies to every message the bot sends, so a customer in the middle of
 * checking out stops getting answers because an announcement was in a
 * hurry. The default here is 15 a second, half of what is allowed, and
 * it is configured rather than constant because a bot with other traffic
 * may want less.
 *
 * Pacing is done against a moving deadline rather than by sleeping a
 * fixed amount after each send. The API call itself takes time, so
 * "send, then wait 66ms" drifts to whatever the network is doing, while
 * "the next send is due at T plus 66ms" holds the actual rate at the
 * number that was configured.
 *
 * **Slices, not one long run.** The worker is a single process running
 * every scheduled job in turn, so a broadcast to a hundred thousand
 * people cannot simply hold it for two hours: everything else the bot
 * schedules would stop. A run therefore sends for a bounded number of
 * seconds, writes down where it got to, and asks to be run again. The
 * cursor on the row is what makes that free -- resuming is a WHERE
 * clause, not state.
 *
 * That same boundary is where a pause takes effect, which is why pausing
 * is immediate in practice and never mid-message.
 */
class Sender
{
    /** Messages a second, unless configured otherwise. */
    public const DEFAULT_RATE = 15;

    /** Seconds of sending per worker run. */
    public const DEFAULT_SLICE = 25;

    /** Recipients fetched per query. */
    public const CHUNK = 250;

    /** Counters are written every this many attempts, and at the end. */
    public const FLUSH_EVERY = 25;

    /** The most recent failure description, kept for the row. */
    private ?string $lastDescription = null;

    public function __construct(
        private Bot $bot,
        private UserRepository $users,
        private BroadcastRepository $broadcasts,
        private Config $config,
        private Logger $log
    ) {
    }

    /** Messages a second this install sends at. */
    public function rate(): int
    {
        return self::clamp((int) $this->config->get('broadcast.rate', self::DEFAULT_RATE), 1, 30);
    }

    /** Seconds one slice spends sending before handing the worker back. */
    public function sliceSeconds(): int
    {
        return self::clamp((int) $this->config->get('broadcast.slice', self::DEFAULT_SLICE), 5, 300);
    }

    /** Roughly how long the whole audience will take, in seconds. */
    public function estimate(int $recipients): int
    {
        return (int) ceil($recipients / max(1, $this->rate()));
    }

    /**
     * Sends until the slice is up, the audience runs out, or it is told
     * to stop.
     *
     * @param  callable|null $keepGoing asked between batches; returning
     *                                  false ends the slice early. The
     *                                  worker passes its lease heartbeat,
     *                                  so losing the lease stops the send
     *                                  rather than letting two workers
     *                                  both deliver.
     * @return bool true when every recipient has been tried
     */
    public function slice(Broadcast $broadcast, ?callable $keepGoing = null): bool
    {
        $id = (int) $broadcast->id;
        $interval = 1 / $this->rate();
        $deadline = microtime(true) + $this->sliceSeconds();
        $due = microtime(true);

        $cursor = (int) $broadcast->cursor;
        $tally = [];
        $pending = 0;
        $finished = false;

        while (microtime(true) < $deadline) {
            if ($keepGoing !== null && $keepGoing() === false) {
                break;
            }

            // Re-read rather than trusting the model this slice began
            // with: an admin pausing from the panel writes the row, and
            // the value of a slice boundary is that it gets noticed.
            $current = $this->broadcasts->find($id);

            if ($current === null || !$current->status()->isActive()) {
                break;
            }

            $batch = $this->users->reachableAfter($cursor, self::CHUNK);

            if ($batch === []) {
                $finished = true;
                break;
            }

            foreach ($batch as $user) {
                $this->pace($due, $interval);

                $outcome = $this->deliver($broadcast, (int) $user->telegram_id);

                if ($outcome === Outcome::THROTTLED) {
                    // Told to slow down: deliver() has already waited it
                    // out, so this is the one retry that recipient gets.
                    // The clock restarts from now, because carrying on at
                    // the old pace is what earned the 429.
                    $outcome = $this->deliver($broadcast, (int) $user->telegram_id);
                    $due = microtime(true) + $interval;
                }

                if ($outcome === Outcome::THROTTLED) {
                    $outcome = Outcome::FAILED;
                }

                $cursor = (int) $user->id;
                $tally[$outcome->column()] = ($tally[$outcome->column()] ?? 0) + 1;
                $pending++;

                if ($outcome->isUnreachable()) {
                    $this->users->markUnreachable((int) $user->telegram_id);
                }

                if ($pending >= self::FLUSH_EVERY) {
                    $this->flush($id, $tally, $cursor);
                    $tally = [];
                    $pending = 0;
                }

                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        }

        $this->flush($id, $tally, $cursor);

        return $finished;
    }

    /**
     * One message to one chat.
     *
     * A copy and a text broadcast differ only here; everything about
     * pacing, counting and resuming is the same for both.
     */
    private function deliver(Broadcast $broadcast, int $telegramId): Outcome
    {
        $message = $broadcast->isCopy()
            ? $this->bot->copyMessage(
                (int) $broadcast->from_chat_id,
                (int) $broadcast->message_id
            )
            : $this->bot->sendMessage((string) $broadcast->body);

        $message->to($telegramId);

        if (!$broadcast->isCopy() && $broadcast->parse_mode) {
            $message->parseMode((string) $broadcast->parse_mode);
        }

        if ($broadcast->silent) {
            $message->silent();
        }

        $response = $message->execute();
        $outcome = Outcome::of($response);

        if ($outcome === Outcome::THROTTLED) {
            $wait = Outcome::retryAfter($response);

            $this->log->notice('Broadcast throttled by Telegram', [
                'broadcast' => (int) $broadcast->id,
                'retry_after' => $wait,
            ]);

            sleep($wait);
        }

        if ($outcome === Outcome::FAILED) {
            $this->lastDescription = (string) ($response['description'] ?? 'no description');
        }

        return $outcome;
    }

    /**
     * Waits until the next send is due, then books the one after it.
     *
     * The due time moves forward by exactly one interval per message
     * however long the API call took, so a slow network makes a
     * broadcast finish later without ever making it send faster than the
     * configured rate.
     */
    private function pace(float &$due, float $interval): void
    {
        $wait = $due - microtime(true);

        if ($wait > 0) {
            usleep((int) round($wait * 1000000));
        }

        // Having fallen behind, the next one is due now rather than in
        // the past: otherwise a stall would be repaid with a burst, which
        // is exactly the shape Telegram counts as flooding.
        $due = max(microtime(true), $due) + $interval;
    }

    /** @param array<string,int> $tally */
    private function flush(int $id, array $tally, int $cursor): void
    {
        if ($tally === []) {
            return;
        }

        $this->broadcasts->tally($id, $tally, $cursor, $this->lastDescription);
        $this->lastDescription = null;
    }

    private static function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }
}
