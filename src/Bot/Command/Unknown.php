<?php

namespace Botex\Bot\Command;

use Botex\Telegram\Bot;
use Botex\Telegram\Update;

class Unknown implements CommandInterface
{

    public function __construct(
        private Bot $bot
    ) {
    }

    public static function command(): string
    {
        return '/unknown';
    }

    public static function button(): string
    {
        return 'unknown';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $this->bot->sendMessage('Command not recognized. Please use /start to begin.')
            ->to($update->chatId());
    }
}