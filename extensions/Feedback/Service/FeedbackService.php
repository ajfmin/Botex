<?php

namespace Extensions\Feedback\Service;

use Botex\Extension\SettingsFactory;
use Botex\Support\Config;
use Botex\Support\Log\Level;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;
use Extensions\Feedback\Model\Response;
use Extensions\Feedback\Repository\QuestionRepository;
use Extensions\Feedback\Repository\ResponseRepository;

/**
 * Stores a submission and notifies the admins.
 *
 * Mixes its own repositories with core services (Bot, Config, settings),
 * which is the pattern an extension is meant to follow.
 */
class FeedbackService
{
    public const SLUG = 'Feedback';

    public function __construct(
        private QuestionRepository $questions,
        private ResponseRepository $responses,
        private SettingsFactory $settings,
        private Config $config,
        private Bot $bot,
        private Logger $log
    ) {
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->for(self::SLUG)->get($key, $default);
    }

    public function maxRating(): int
    {
        $max = (int) $this->setting('max_rating', 5);

        // keep the keyboard to one sane row whatever the setting says
        return max(2, min(10, $max));
    }

    /** @return array<int, string> question id => text */
    public function activeQuestions(): array
    {
        $map = [];

        foreach ($this->questions->active() as $question) {
            $map[(int) $question->id] = (string) $question->text;
        }

        return $map;
    }

    /**
     * @param array<int, string> $answers question id => answer
     */
    public function submit(int $telegramId, int $rating, array $answers): Response
    {
        $response = $this->responses->create($telegramId, $rating, $answers);

        if ($this->setting('notify_admins', true)) {
            $this->notify($response);
        }

        return $response;
    }

    /** @return array{count:int, average:float|null, questions:int} */
    public function stats(): array
    {
        return [
            'count' => $this->responses->count(),
            'average' => $this->responses->averageRating(),
            'questions' => $this->questions->count(),
        ];
    }

    /**
     * Sends the submission to every admin. A failed send is logged and
     * skipped so one unreachable admin cannot lose the feedback.
     */
    private function notify(Response $response): void
    {
        $admins = (array) $this->config->get('admins', []);

        if (!$admins) {
            return;
        }

        $text = $this->format($response);

        foreach ($admins as $admin) {
            try {
                $this->bot->sendMessage($text)
                    ->to((int) $admin)
                    ->parseMode('HTML')
                    ->execute();
            } catch (\Throwable $e) {
                // Warning, not error: the feedback itself is stored, only
                // the heads-up to this one admin was lost.
                $this->log->exception($e, "Feedback notify failed for {$admin}", Level::Warning, [
                    'extension' => self::SLUG,
                    'user' => (int) $response->telegram_id,
                ]);
            }
        }
    }

    private function format(Response $response): string
    {
        $lines = [
            '<b>New feedback</b>',
            '',
            'From: <code>' . (int) $response->telegram_id . '</code>',
            'Rating: ' . str_repeat('*', (int) $response->rating)
                . ' (' . (int) $response->rating . '/' . $this->maxRating() . ')',
        ];

        $questions = [];

        foreach ($this->questions->all() as $question) {
            $questions[(int) $question->id] = (string) $question->text;
        }

        foreach ((array) $response->answers as $questionId => $answer) {
            $label = $questions[(int) $questionId] ?? 'Question #' . $questionId;

            $lines[] = '';
            $lines[] = '<b>' . $this->escape($label) . '</b>';
            $lines[] = $this->escape((string) $answer);
        }

        return implode(PHP_EOL, $lines);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
