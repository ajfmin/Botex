<?php

namespace Extensions\Feedback\Flow\Step;

use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\RepeatableStep;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\TextStep;
use Extensions\Feedback\Service\FeedbackService;

/**
 * Walks the admin-defined questions one at a time.
 *
 * The question list is seeded into the session when the flow starts, so
 * an admin editing questions mid-conversation cannot shift a user onto a
 * different question than the one they were shown.
 */
class AskQuestions extends TextStep implements RepeatableStep
{
    public const KEY = 'answers';

    protected int $maxLength = 1000;

    public function __construct(
        private FeedbackService $feedback
    ) {
    }

    public static function name(): string
    {
        return 'questions';
    }

    public function question(Session $session): string
    {
        $pending = $this->pending($session);
        $text = reset($pending);

        return $text === false ? 'No questions to ask.' : (string) $text;
    }

    public function prompt(Session $session): Prompt
    {
        $asked = count($this->answers($session)) + 1;
        $total = count((array) $session->get('questions', []));

        return new Prompt(
            sprintf(
                '<b>Question %d of %d</b>%s%s',
                $asked,
                $total,
                PHP_EOL,
                $this->escape($this->question($session))
            )
        );
    }

    public function store(Session $session, mixed $value): void
    {
        $pending = $this->pending($session);
        $questionId = array_key_first($pending);

        if ($questionId === null) {
            return;
        }

        $answers = $this->answers($session);
        $answers[$questionId] = (string) $value;

        $session->set(self::KEY, $answers);
    }

    public function hasMore(Session $session): bool
    {
        return $this->pending($session) !== [];
    }

    /** @return array<int|string, string> questions not yet answered */
    private function pending(Session $session): array
    {
        $questions = (array) $session->get('questions', []);

        return array_diff_key($questions, $this->answers($session));
    }

    /** @return array<int|string, string> */
    private function answers(Session $session): array
    {
        return (array) $session->get(self::KEY, []);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
