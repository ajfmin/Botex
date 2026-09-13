<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\Panel;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

class Home implements CallbackInterface
{
    public function __construct(
        private Bot $bot,
        private Panel $panel
    ) {
    }

    public static function callback(): string
    {
        return Panel::HOME;
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function handle(Update $update): void
    {
        $callbackId = $update->callbackId();

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId);
        }

        $this->panel->show($update, '<b>Admin panel</b>', $this->panel->menu());
    }
}
