<?php

namespace Extensions\Feedback\Flow\Step;

use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\ChoiceStep;
use Extensions\Feedback\Service\FeedbackService;

/**
 * Star rating as buttons. Extends ChoiceStep, so an out-of-range value
 * from a stale keyboard is rejected without extra code here.
 */
class AskRating extends ChoiceStep
{
    protected int $perRow = 5;

    public function __construct(
        private FeedbackService $feedback
    ) {
    }

    public static function name(): string
    {
        return 'rating';
    }

    public function question(Session $session): string
    {
        return (string) $this->feedback->setting(
            'rating_question',
            'How would you rate us overall?'
        );
    }

    public function choices(Session $session): array
    {
        $choices = [];

        for ($i = 1; $i <= $this->feedback->maxRating(); $i++) {
            $choices[(string) $i] = str_repeat('*', $i);
        }

        return $choices;
    }
}
