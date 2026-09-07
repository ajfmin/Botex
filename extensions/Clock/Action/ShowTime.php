<?php

namespace Extensions\Clock\Action;

use Botex\Bot\Action\RunContext;
use Botex\Bot\Action\RunnableInterface;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Keyboard\ClockKeyboard;
use Extensions\Clock\Service\ClockService;

/**
 * Shows the time in whichever zone the pressed button was bound to.
 *
 * The zone arrives in the action data, not in the callback payload, so
 * the button itself carries nothing but an opaque token. Works the same
 * whether the press came from an inline button or a reply-keyboard
 * label, because the runner hands both the same context.
 */
class ShowTime implements RunnableInterface
{
    public function __construct(
        private Bot $bot,
        private ClockService $clock,
        private ClockKeyboard $keyboard
    ) {
    }

    public static function name(): string
    {
        return 'show_time';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update, RunContext $context): void
    {
        $zone = $context->string('zone', $this->clock->defaultZone());
        $text = $this->clock->text($zone);
        $messageId = $update->messageId();

        // An inline press edits the message it came from; a label press
        // has no message of ours to edit, so it sends a new one.
        if (!$context->isReply() && $messageId !== null) {
            $this->bot->editMessage($text, $messageId)
                ->to($update->chatId())
                ->parseMode('HTML')
                ->replyMarkup($this->keyboard->inline($update, $zone));

            return;
        }

        $this->bot->sendMessage($text)
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard->inline($update, $zone));
    }
}
