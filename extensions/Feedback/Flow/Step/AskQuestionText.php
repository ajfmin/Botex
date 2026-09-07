<?php

namespace Extensions\Feedback\Flow\Step;

use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\TextStep;

class AskQuestionText extends TextStep
{
    protected int $minLength = 3;

    protected int $maxLength = 255;

    public static function name(): string
    {
        return 'question_text';
    }

    public function question(Session $session): string
    {
        return 'Send the text of the new question.';
    }

    public function prompt(Session $session): Prompt
    {
        return new Prompt($this->question($session));
    }
}
