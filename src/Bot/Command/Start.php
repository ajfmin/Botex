<?php

namespace Botex\Bot\Command;

use Botex\Telegram\Update;
use Botex\Telegram\Bot;
use Botex\Service\UserService;

class Start implements CommandInterface
{

    public function __construct(
        private Bot $bot,
        private UserService $userService
    ) {
    }

    public static function command(): string
    {
        return '/start';
    }

    public static function button(): string
    {
        return 'Start';
    }

    public static function middleware(): array
    {
        return [
            \Botex\Bot\Middleware\NotBlocked::class,
        ];
    }
    public function handle(Update $update): void
    {

        $user = $this->userService->findOrCreateUser($update->fromId());

        $this->bot->sendMessage('Hi ' . $user->id . ', Welcome to the bot!')
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup(
                \Botex\Bot\Keyboard\MainMenuKeyboard::make()
            );
    }
}