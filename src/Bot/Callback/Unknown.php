<?php

namespace Botex\Bot\Callback;

use Botex\Telegram\Bot;
use Botex\Telegram\Update;

class Unknown implements CallbackInterface
{

    public function __construct(
        private Bot $bot
    ) {
    }

    public static function callback(): string
    {
        return 'unknown';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $this->bot->sendMessage('Callback not recognized. Please use /start to begin.')
            ->to($update->chatId());
    }
}