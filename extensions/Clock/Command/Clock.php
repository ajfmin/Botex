<?php

namespace Extensions\Clock\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Service\UserService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Keyboard\ClockKeyboard;
use Extensions\Clock\Service\ClockService;

/**
 * Takes ClockService (its own) and UserService (core) to show that an
 * extension can mix both.
 */
class Clock implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private ClockService $clock,
        private ClockKeyboard $keyboard,
        private UserService $userService
    ) {
    }

    public static function command(): string
    {
        return '/clock';
    }

    public static function button(): string
    {
        return 'Clock';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $this->userService->findOrCreateUser((int) $update->fromId());

        $this->bot->sendMessage($this->clock->text())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard->inline($update));
    }
}
