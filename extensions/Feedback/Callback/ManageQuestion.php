<?php

namespace Extensions\Feedback\Callback;

use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Feedback\Admin\FeedbackSection;
use Extensions\Feedback\Repository\QuestionRepository;

/**
 * Toggle and delete for a single question.
 *
 * Gated by the core IsAdmin middleware: an extension callback reuses the
 * same gate as core, rather than rolling its own permission check.
 */
class ManageQuestion implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private Bot $bot,
        private QuestionRepository $questions,
        private FeedbackSection $section
    ) {
    }

    public static function callback(): string
    {
        return 'feedback:admin:question';
    }

    public static function matches(string $data): bool
    {
        return self::parse($data) !== null;
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
        $parsed = self::parse((string) $update->callbackData());

        if ($parsed === null) {
            return;
        }

        [$action, $id] = $parsed;

        $notice = match ($action) {
            'toggle' => $this->toggle($id),
            'delete' => $this->delete($id),
            default => '',
        };

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId, $notice);
        }

        $this->section->refresh($update);
    }

    private function toggle(int $id): string
    {
        $question = $this->questions->toggle($id);

        if (!$question) {
            return 'That question no longer exists.';
        }

        return $question->active ? 'Enabled.' : 'Disabled.';
    }

    private function delete(int $id): string
    {
        return $this->questions->delete($id)
            ? 'Deleted.'
            : 'That question no longer exists.';
    }

    /** @return array{0:string, 1:int}|null */
    private static function parse(string $data): ?array
    {
        foreach (['toggle' => FeedbackSection::TOGGLE, 'delete' => FeedbackSection::DELETE] as $action => $prefix) {
            if (!str_starts_with($data, $prefix)) {
                continue;
            }

            $id = substr($data, strlen($prefix));

            if (ctype_digit($id)) {
                return [$action, (int) $id];
            }
        }

        return null;
    }
}
