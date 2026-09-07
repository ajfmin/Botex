<?php

namespace Botex\Bot;

use Botex\Telegram\Update;

/**
 * The update being handled right now, if there is one.
 *
 * A reply-keyboard action has to be scoped to one user, because a label
 * carries no token back and two users pressing "UTC" must resolve to their
 * own rows. Nothing in a button's syntax says who it is for, so the binder
 * reads it from here instead of every call site repeating it.
 *
 * Set once per update by the webhook, and empty in the worker and the
 * console, where there is no request. That is the case a caller has to
 * handle explicitly: a menu action bound from a job has to name its
 * audience, since no ambient user exists.
 */
class CurrentUpdate
{
    private ?Update $update = null;

    public function set(Update $update): void
    {
        $this->update = $update;
    }

    public function forget(): void
    {
        $this->update = null;
    }

    public function get(): ?Update
    {
        return $this->update;
    }

    public function has(): bool
    {
        return $this->update !== null;
    }

    /** Telegram id of whoever sent the update being handled. */
    public function telegramId(): ?int
    {
        $id = $this->update?->fromId();

        return $id === null ? null : (int) $id;
    }
}
