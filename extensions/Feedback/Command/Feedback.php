<?php

namespace Extensions\Feedback\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Bot\Middleware\NotBlocked;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Feedback\Flow\FeedbackFlow;
use Extensions\Feedback\Service\FeedbackService;

class Feedback implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private FlowRunner $runner,
        private FeedbackService $feedback
    ) {
    }

    public static function command(): string
    {
        return '/feedback';
    }

    public static function button(): string
    {
        return 'Feedback';
    }

    /** An extension command using a core middleware. */
    public static function middleware(): array
    {
        return [
            NotBlocked::class,
        ];
    }

    public function handle(Update $update): void
    {
        $questions = $this->feedback->activeQuestions();

        if (!$questions) {
            $this->bot->sendMessage('Feedback is not set up yet. Please try again later.')
                ->to($update->chatId());

            return;
        }

        $this->bot->sendMessage(
            (string) $this->feedback->setting('intro_text', 'We would love your feedback.')
        )->to($update->chatId());

        // Snapshot the questions so the form cannot change underneath the
        // user if an admin edits them while this one is being filled in.
        $this->runner->start(
            FeedbackFlow::name(),
            $update,
            ['questions' => $questions]
        );
    }
}
