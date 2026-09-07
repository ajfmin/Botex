<?php

namespace Extensions\Clock\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Keyboard\ClockKeyboard;

/**
 * Installs a reply keyboard of zone labels.
 *
 * The reply-keyboard half of the Run demo: pressing one sends only its
 * label, which resolves back to this user's binding for it.
 */
class Zones implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private ClockKeyboard $keyboard
    ) {
    }

    public static function command(): string
    {
        return '/zones';
    }

    public static function button(): string
    {
        return 'Zones';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $this->bot->sendMessage('Pick a zone from the keyboard below.')
            ->to($update->chatId())
            ->replyMarkup($this->keyboard->menu($update));
    }
}
