<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Conversation\Answer;
use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\TextStep;
use Botex\Telegram\Update;

class AskTelegramId extends TextStep
{
    public static function name(): string
    {
        return 'telegram_id';
    }

    public function question(Session $session): string
    {
        return 'Send the telegram id of the user to '
            . htmlspecialchars((string) $session->get('action'), ENT_QUOTES, 'UTF-8')
            . '.';
    }

    public function prompt(Session $session): Prompt
    {
        return new Prompt($this->question($session));
    }

    public function validate(Update $update, Session $session): Answer
    {
        $answer = parent::validate($update, $session);

        if (!$answer->valid) {
            return $answer;
        }

        $text = (string) $answer->value;

        if (!ctype_digit($text)) {
            return Answer::error('A telegram id is digits only. Please try again.');
        }

        return Answer::ok((int) $text);
    }
}
