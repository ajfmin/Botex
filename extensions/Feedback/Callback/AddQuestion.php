<?php

namespace Extensions\Feedback\Callback;

use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Feedback\Admin\FeedbackSection;
use Extensions\Feedback\Flow\AddQuestionFlow;

/**
 * Starts the admin flow that adds a question. An extension flow driven
 * from an extension callback.
 */
class AddQuestion implements CallbackInterface
{
    public function __construct(
        private Bot $bot,
        private FlowRunner $runner
    ) {
    }

    public static function callback(): string
    {
        return FeedbackSection::ADD;
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

        $this->runner->start(AddQuestionFlow::name(), $update);
    }
}
