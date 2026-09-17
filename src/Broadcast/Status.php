<?php

namespace Botex\Broadcast;

/**
 * Where a broadcast is in its life.
 *
 * QUEUED and SENDING are the working states; the rest are where a
 * broadcast stops. PAUSED is deliberately not terminal -- it is the
 * state an admin puts one in when they spot a typo halfway through a
 * hundred thousand messages, and getting back out of it has to be one
 * button.
 */
enum Status: string
{
    /** Created, audience not yet counted, worker has not looked. */
    case QUEUED = 'queued';

    /** Being delivered right now, one slice per worker run. */
    case SENDING = 'sending';

    /** Held by an admin. The cursor is kept, so resuming carries on. */
    case PAUSED = 'paused';

    /** Every recipient was tried. */
    case DONE = 'done';

    /** Stopped by an admin and not resumable. */
    case CANCELLED = 'cancelled';

    /** Whether the worker should keep picking this up. */
    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::SENDING;
    }

    public function isFinished(): bool
    {
        return $this === self::DONE || $this === self::CANCELLED;
    }

    /** Whether an admin can still start it moving again. */
    public function isResumable(): bool
    {
        return $this === self::PAUSED;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::QUEUED => '🕓',
            self::SENDING => '📤',
            self::PAUSED => '⏸',
            self::DONE => '✅',
            self::CANCELLED => '✖️',
        };
    }
}
