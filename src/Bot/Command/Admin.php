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

    /**
     * Both keyboards, in two messages, because Telegram allows one
     * reply_markup per message and these are different kinds of markup.
     *
     * The keyboard goes first and quietly; the panel follows and is the
     * message the admin actually reads and navigates in place.
     */
    public function handle(Update $update): void
    {
        $this->bot->sendMessage('Admin menu is on the keyboard below.')
            ->to($update->chatId())
            ->replyMarkup($this->panel->menuKeyboard())
            ->execute();

        $this->panel->show($update, '<b>Admin panel</b>', $this->panel->menu());
    }
}
