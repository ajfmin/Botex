<?php

namespace Botex\Bot\Conversation\Step;

use Botex\Bot\Conversation\Answer;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\StepInterface;
use Botex\Telegram\Update;

/**
 * Base for steps that read a line of text.
 *
 * Trims, enforces a length range, and rejects anything that is not a
 * text message, so subclasses only override what differs.
 */
abstract class TextStep implements StepInterface
{
    protected int $minLength = 1;

    protected int $maxLength = 4096;

    abstract public function question(Session $session): string;

    public function validate(Update $update, Session $session): Answer
    {
        $text = $update->text();

        if ($text === null) {
            return Answer::error('Please send this as a text message.');
        }

        $text = trim($text);

        // a command means the user is trying to leave, not answer
        if (str_starts_with($text, '/')) {
            return Answer::error('Please answer the question, or press Cancel to stop.');
        }

        $length = mb_strlen($text);

        if ($length < $this->minLength) {
            return Answer::error("Please write at least {$this->minLength} characters.");
        }

        if ($length > $this->maxLength) {
            return Answer::error("Please keep it under {$this->maxLength} characters.");
        }

        return Answer::ok($text);
    }
}
