<?php

namespace Extensions\Clock\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Bot\Job\Schedule;
use Botex\Service\JobService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Job\SendTime;

/**
 * /remind [minutes] - sends the time once, later.
 *
 * The one-shot half of the job demo, and the "run +30 minutes" case from
 * the brief. Note that handle() returns immediately: it writes one row
 * and replies. Nothing sleeps, nothing is held open.
 */
class Remind implements CommandInterface
{
    /** Used when the command comes with no number. */
    private const DEFAULT_MINUTES = 30;

    /** Anything longer is almost certainly a typo. */
    private const MAX_MINUTES = 10080;

    public function __construct(
        private Bot $bot,
        private JobService $jobs
    ) {
    }

    public static function command(): string
    {
        return '/remind';
    }

    public static function button(): string
    {
        return 'Remind me';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $minutes = $this->minutes($update->text());

        $job = $this->jobs->schedule(
            \Botex\Bot\Job\JobRequest::to(
                'Clock',
                SendTime::name(),
                Schedule::in($minutes * 60),
                [
                    'chat' => $update->chatId(),
                    'note' => 'Reminder',
                ]
            )
        );

        $this->bot->sendMessage(
            "I'll send you the time in {$minutes} minute(s)."
            . PHP_EOL . 'Job #' . (int) $job->id . ', ' . $job->describeNextRun() . '.'
        )->to($update->chatId());
    }

    /** Reads the argument, clamped to something sensible. */
    private function minutes(?string $text): int
    {
        $parts = preg_split('/\s+/', trim((string) $text)) ?: [];
        $argument = $parts[1] ?? '';

        if (!ctype_digit($argument) || (int) $argument < 1) {
            return self::DEFAULT_MINUTES;
        }

        return min((int) $argument, self::MAX_MINUTES);
    }
}
