<?php

namespace Extensions\Feedback\Flow;

use Botex\Bot\Conversation\FlowInterface;
use Botex\Bot\Conversation\Session;
use Botex\Telegram\Bot;
use Extensions\Feedback\Flow\Step\AskQuestions;
use Extensions\Feedback\Flow\Step\AskRating;
use Extensions\Feedback\Service\FeedbackService;

/**
 * Rating first, then the admin-defined questions.
 *
 * The question set is a snapshot taken at start (see the command), so a
 * form stays coherent even if an admin edits questions mid-flow.
 */
class FeedbackFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private FeedbackService $feedback
    ) {
    }

    public static function name(): string
    {
        return 'feedback.form';
    }

    public static function steps(): array
    {
        return [
            AskRating::class,
            AskQuestions::class,
        ];
    }

    public function complete(Session $session): void
    {
        $this->feedback->submit(
            telegramId: $session->telegramId,
            rating: (int) $session->get(AskRating::name(), 0),
            answers: (array) $session->get(AskQuestions::KEY, [])
        );

        $thanks = (string) $this->feedback->setting(
            'thanks_text',
            'Thank you for your feedback.'
        );

        $this->bot->sendMessage($thanks)->to($session->telegramId);
    }
}
