<?php

namespace Extensions\Clock\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Bot\Job\JobRequest;
use Botex\Bot\Job\Schedule;
use Botex\Service\JobService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Extensions\Clock\Job\SendTime;

/**
 * /watch [minutes] - sends the time every N minutes; /watch stop ends it.
 *
 * The recurring half of the job demo. Keyed per chat, so asking twice
 * re-arms the one schedule instead of stacking up a second copy that
 * would double every message.
 */
class Watch implements CommandInterface
{
    private const DEFAULT_MINUTES = 5;

    private const MAX_MINUTES = 1440;

    /** Stops after this many sends, so a forgotten watch is not forever. */
    private const RUNS = 12;

    public function __construct(
        private Bot $bot,
        private JobService $jobs
    ) {
    }

    public static function command(): string
    {
        return '/watch';
    }

    public static function button(): string
    {
        return 'Watch clock';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $key = 'Clock:watch:' . $update->chatId();
        $argument = $this->argument($update->text());

        if ($argument === 'stop') {
            $this->stop($key, $update);

            return;
        }

        $minutes = ctype_digit($argument) && (int) $argument >= 1
            ? min((int) $argument, self::MAX_MINUTES)
            : self::DEFAULT_MINUTES;

        $job = $this->jobs->schedule(
            JobRequest::to(
                'Clock',
                SendTime::name(),
                Schedule::everyMinutes($minutes, self::RUNS)->startingNow(),
                [
                    'chat' => $update->chatId(),
                    'note' => 'Clock watch',
                ]
            )->keyed($key)
        );

        $this->bot->sendMessage(
            "Watching the clock every {$minutes} minute(s), " . self::RUNS . ' times.'
            . PHP_EOL . 'Job #' . (int) $job->id . '. Send /watch stop to cancel.'
        )->to($update->chatId());
    }

    private function stop(string $key, Update $update): void
    {
        $job = $this->jobs->findByKey($key);

        $message = $job && $this->jobs->cancel((int) $job->id)
            ? 'Stopped watching the clock.'
            : 'Nothing to stop.';

        $this->bot->sendMessage($message)->to($update->chatId());
    }

    private function argument(?string $text): string
    {
        $parts = preg_split('/\s+/', trim((string) $text)) ?: [];

        return strtolower(trim($parts[1] ?? ''));
    }
}
