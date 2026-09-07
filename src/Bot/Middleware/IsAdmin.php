<?php

namespace Botex\Bot\Middleware;

use Botex\Support\Config;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

class IsAdmin implements MiddlewareInterface
{
    public function __construct(
        private Bot $bot,
        private Config $config
    ) {
    }

    public function handle(Update $update): bool
    {
        $admins = $this->config->get('admins', []);
        $fromId = $update->fromId();

        if ($fromId !== null && in_array((int) $fromId, $admins, true)) {
            return true;
        }

        $this->bot->sendMessage('You are not allowed to use this command.')
            ->to($update->chatId());

        return false;
    }
}
