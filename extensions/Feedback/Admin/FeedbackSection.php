<?php

namespace Extensions\Feedback\Admin;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Feedback\Repository\QuestionRepository;
use Extensions\Feedback\Service\FeedbackService;

/**
 * An extension's own admin panel. Registered through the extension's
 * adminSections() hook and reached via admin:s:feedback, so it appears
 * on the admin home screen next to the core sections.
 */
class FeedbackSection implements AdminSectionInterface
{
    public const ADD = 'feedback:admin:add';

    public const TOGGLE = 'feedback:admin:toggle:';

    public const DELETE = 'feedback:admin:delete:';

    public function __construct(
        private Bot $bot,
        private QuestionRepository $questions,
        private FeedbackService $feedback
    ) {
    }

    public static function key(): string
    {
        return 'feedback';
    }

    public static function title(): string
    {
        return 'Feedback';
    }

    public function handle(Update $update): void
    {
        $this->bot->editMessage($this->text(), (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard());
    }

    /** Re-renders after a change, reusing the same message. */
    public function refresh(Update $update, string $notice = ''): void
    {
        $text = $notice === '' ? $this->text() : $notice . PHP_EOL . PHP_EOL . $this->text();

        $this->bot->editMessage($text, (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard());
    }

    private function text(): string
    {
        $stats = $this->feedback->stats();

        $lines = [
            '<b>Feedback</b>',
            '',
            'Responses: ' . $stats['count'],
            'Average rating: ' . ($stats['average'] ?? 'n/a'),
            '',
            '<b>Questions</b>',
        ];

        $questions = $this->questions->all();

        if (!$questions) {
            $lines[] = 'None yet. Add one below.';
        }

        foreach ($questions as $question) {
            $lines[] = sprintf(
                '%s %s',
                $question->active ? '[on] ' : '[off]',
                $this->escape((string) $question->text)
            );
        }

        return implode(PHP_EOL, $lines);
    }

    private function keyboard(): array
    {
        $keyboard = Keyboard::inline()
            ->row(InlineButton::callback('Add question', self::ADD));

        foreach ($this->questions->all() as $question) {
            $id = (int) $question->id;

            $keyboard->row(
                InlineButton::callback(
                    ($question->active ? 'Disable: ' : 'Enable: ') . $this->truncate((string) $question->text),
                    self::TOGGLE . $id
                ),
                InlineButton::callback('Delete', self::DELETE . $id)
            );
        }

        return $keyboard
            ->row(InlineButton::callback('Back', Panel::HOME))
            ->build();
    }

    private function truncate(string $value, int $length = 20): string
    {
        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 1) . '...'
            : $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
