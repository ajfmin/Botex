<?php

namespace Botex\Model;

use Botex\Broadcast\Status;
use Illuminate\Database\Eloquent\Model;

/**
 * One announcement, and how far through its audience it is.
 *
 * The row is the whole state of the send. There is no queue table with
 * a row per recipient, on purpose: an audience is the users table, and
 * duplicating it per broadcast would write a million rows to send a
 * million messages. What is stored instead is a cursor -- the highest
 * users.id already tried -- which is enough to resume after a pause, a
 * crash, or a worker restart, and costs one indexed range scan per
 * batch.
 *
 * The price of that choice is that a user who joins mid-broadcast is
 * included (their id is above the cursor) and one who joined before it
 * started is not missed. Both are the behaviour you want from an
 * announcement.
 *
 * Counters are incremented with SQL rather than read-modify-written, so
 * the panel can watch a broadcast move without ever racing the worker
 * that is moving it.
 */
class Broadcast extends Model
{
    protected $table = 'broadcasts';

    /** A message of the admin's own, repeated to everyone. */
    public const COPY = 'copy';

    /** Text composed somewhere with no chat to copy from, e.g. the CLI. */
    public const TEXT = 'text';

    protected $fillable = [
        'created_by',
        'kind',
        'from_chat_id',
        'message_id',
        'body',
        'parse_mode',
        'silent',
        'status',
        'total',
        'sent',
        'blocked',
        'gone',
        'failed',
        'skipped',
        'cursor',
        'last_error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'from_chat_id' => 'integer',
        'message_id' => 'integer',
        'silent' => 'boolean',
        'total' => 'integer',
        'sent' => 'integer',
        'blocked' => 'integer',
        'gone' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'cursor' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function status(): Status
    {
        return Status::tryFrom((string) $this->status) ?? Status::QUEUED;
    }

    public function isCopy(): bool
    {
        return $this->kind === self::COPY;
    }

    /** Every recipient Telegram was actually asked about. */
    public function attempted(): int
    {
        return (int) $this->sent + (int) $this->blocked
            + (int) $this->gone + (int) $this->failed;
    }

    /**
     * Everyone the send has finished with, tried or not.
     *
     * The gap between this and attempted() is people who were in the
     * audience when it began and had left it by the time their turn came
     * -- blocked by an admin, or already marked unreachable by an earlier
     * message in this same send. They are written down when the broadcast
     * closes, because otherwise a completed send sits at 98% forever and
     * reads as stuck rather than finished.
     */
    public function accounted(): int
    {
        return $this->attempted() + (int) $this->skipped;
    }

    /**
     * How far through, 0-100.
     *
     * Against total, which was measured when the send began -- so it can
     * read slightly over on a bot gaining users mid-broadcast. Clamped
     * rather than recounted: a progress bar that goes backwards because
     * the denominator moved is worse than one that sits at 100 for a
     * moment.
     */
    public function percent(): int
    {
        $total = (int) $this->total;

        return $total < 1 ? 0 : min(100, (int) floor($this->accounted() / $total * 100));
    }

    /** Recipients not yet reached, as far as the stored total knows. */
    public function remaining(): int
    {
        return max(0, (int) $this->total - $this->accounted());
    }

    /** What the admin composed, in one short line for a list. */
    public function preview(int $length = 48): string
    {
        $body = trim(preg_replace('/\s+/', ' ', (string) $this->body) ?? '');

        if ($body === '') {
            return $this->isCopy() ? 'a copied message' : 'an empty message';
        }

        return mb_strlen($body) > $length ? mb_substr($body, 0, $length - 1) . '…' : $body;
    }
}
