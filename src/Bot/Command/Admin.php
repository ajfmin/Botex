<?php

namespace Botex\Bot\Command;

use Botex\Bot\Admin\Panel;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

class Admin implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private Panel $panel
    ) {
    }

    public static function command(): string
    {
        return '/admin';
    }

    public static function button(): string
    {
        return 'Admin';
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function handle(Update $update): void
    {
        $this->bot->sendMessage('<b>Admin panel</b>')
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->panel->menu());
    }
}
