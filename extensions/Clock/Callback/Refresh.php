<?php

namespace Extensions\Clock\Callback;

use Botex\Bot\Callback\CallbackInterface;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Keyboard\ClockKeyboard;
use Extensions\Clock\Service\ClockService;

/**
 * A plain fixed-callback handler, kept alongside the Run buttons to show
 * both styles still work. Use this shape when a button needs no data;
 * use a Run action when it does.
 */
class Refresh implements CallbackInterface
{
    public function __construct(
        private Bot $bot,
        private ClockService $clock,
        private ClockKeyboard $keyboard
    ) {
    }

    public static function callback(): string
    {
        return 'clock:refresh';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $callbackId = $update->callbackId();
        $messageId = $update->messageId();

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId, 'Updated');
        }

        if ($messageId === null) {
            return;
        }

        $this->bot->editMessage($this->clock->text(), $messageId)
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard->inline($update));
    }
}
