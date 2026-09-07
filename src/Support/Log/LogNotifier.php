<?php

namespace Botex\Support\Log;

use Botex\Telegram\Bot;

/**
 * Tells the admins, in telegram, about log entries at or above a threshold.
 *
 * Deliberately narrower than the file. Callback data, raw update payloads
 * and full class names stay out of it: a chat is forwardable, screenshot-
 * able, and backed up on someone's phone, so it carries the minimum needed
 * to react, and `origin` is the pointer to the full record in the file.
 */
class LogNotifier
{
    /** A group has a short burst; after this many, the file is on its own. */
    public const WINDOW_LIMIT = 15;

    /** Telegram truncates long messages, so leave room for the notice. */
    public const MAX_CHARS = 3500;

    /**
     * Entries sent in the current minute, to bound a crash loop. Not
     * per-instance: the facade, the logger and any second logger share one
     * budget, which is the point.
     */
    private static int $windowCount = 0;

    private static int $windowStart = 0;

    public function __construct(
        private Bot $bot,
        private int|string $chatId,
        private Level $minLevel = Level::Error
    ) {
    }

    public function send(Level $level, string $message, array $context): void
    {
        if (!$level->atLeast($this->minLevel)) {
            return;
        }

        if ($this->throttled($level)) {
            return;
        }

        // execute() is called explicitly so a failure surfaces in the
        // logger's try/catch rather than from the builder's destructor
        // outside it. execute() caches its result, so the destructor then
        // has nothing left to send.
        $response = $this->bot->sendMessage($this->render($level, $message, $context))
            ->to($this->chatId)
            ->execute();

        // A rejected send is not an exception: the transport reports it as
        // ok=false, and it has to reach the file or a misconfigured chat id
        // means no admin ever hears about anything.
        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'Log chat rejected the message: '
                . json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR)
            );
        }
    }

    /**
     * Counts the send against the current minute and drops it once the
     * window is full, saying so once more before going quiet.
     */
    private function throttled(Level $level): bool
    {
        $minute = (int) floor(microtime(true) / 60);

        if ($minute !== self::$windowStart) {
            self::$windowStart = $minute;
            self::$windowCount = 0;
        }

        self::$windowCount++;

        if (self::$windowCount <= self::WINDOW_LIMIT) {
            return false;
        }

        // Exactly one "these are being dropped" message per window, so the
        // admins learn logging went quiet without it becoming the new noise.
        if (self::$windowCount === self::WINDOW_LIMIT + 1) {
            $this->bot->sendMessage(
                "[{$level->label()}] Notification limit reached ({$level->name} per minute)."
                . ' Further entries are in the log file only.'
            )->to($this->chatId)->execute();
        }

        return true;
    }

    /** Plain text, one line per fact. No parse mode, so nothing to escape. */
    private function render(Level $level, string $message, array $context): string
    {
        $lines = ["[{$level->label()}] " . $this->clip($this->safe($message))];

        // The logger passes records before write() turns the throwable into
        // its array form, so accept both shapes.
        $exception = $context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $lines[] = 'exception: ' . $this->clip($this->safe($exception->getMessage()));
        } elseif (is_array($exception)) {
            $lines[] = 'exception: '
                . $this->clip($this->safe((string) ($exception['message'] ?? '(none)')));
        }

        foreach (['type', 'command', 'extension', 'action', 'user', 'chat', 'origin'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $lines[] = $key . ': ' . $this->safe((string) $context[$key]);
            }
        }

        return $this->clip(implode("\n", $lines), self::MAX_CHARS);
    }

    /**
     * Strips namespaces out of text headed for the chat.
     *
     * Exception messages quote the classes they could not resolve, and a
     * class name is a path into the codebase. The short name still says
     * what failed; the file keeps the fully qualified one.
     */
    private function safe(string $text): string
    {
        return (string) preg_replace_callback(
            '/[A-Za-z0-9_\\\\]+/',
            function (array $m) {
                // A run with no backslash is an ordinary word; one with
                // backslashes is a class name, and only its tail matters.
                return str_contains($m[0], '\\')
                    ? substr($m[0], (int) strrpos($m[0], '\\') + 1)
                    : $m[0];
            },
            $text
        );
    }

    private function clip(string $text, int $max = 300): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)) . '...';
    }
}
