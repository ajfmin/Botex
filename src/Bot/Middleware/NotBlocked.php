<?php

namespace Botex\Bot\Middleware;

use Botex\Service\UserService;
use Botex\Telegram\Update;

/**
 * Stops blocked users. Silent on purpose: telling someone they are
 * blocked invites them to keep poking at the bot.
 */
class NotBlocked implements MiddlewareInterface
{
    public function __construct(
        private UserService $users
    ) {
    }

    public function handle(Update $update): bool
    {
        $fromId = $update->fromId();

        if ($fromId === null) {
            return true;
        }

        return !$this->users->isBlocked((int) $fromId);
    }
}
