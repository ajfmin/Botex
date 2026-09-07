<?php

namespace Extensions\Feedback\Flow;

use Botex\Bot\Admin\Panel;
use Botex\Bot\Conversation\FlowInterface;
use Botex\Bot\Conversation\Session;
use Botex\Telegram\Bot;
use Extensions\Feedback\Flow\Step\AskQuestionText;
use Extensions\Feedback\Repository\QuestionRepository;

/** Admin flow: one step, then the question is saved. */
class AddQuestionFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private QuestionRepository $questions
    ) {
    }

    public static function name(): string
    {
        return 'feedback.add_question';
    }

    public static function steps(): array
    {
        return [
            AskQuestionText::class,
        ];
    }

    public function complete(Session $session): void
    {
        $text = (string) $session->get(AskQuestionText::name(), '');

        if ($text === '') {
            return;
        }

        $this->questions->add($text);

        $this->bot->sendMessage('Question added.')
            ->to($session->telegramId)
            ->replyMarkup(Panel::backKeyboard());
    }
}
