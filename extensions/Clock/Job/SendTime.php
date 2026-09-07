<?php

namespace Extensions\Clock\Job;

use Botex\Bot\Job\JobContext;
use Botex\Bot\Job\JobInterface;
use Botex\Telegram\Bot;
use Extensions\Clock\Service\ClockService;

/**
 * Sends the time to a chat, later.
 *
 * The test case for the job system, and the same class serves both
 * shapes: /remind schedules it once at +N minutes, /watch schedules it
 * every N minutes. Nothing here knows which; the schedule lives on the
 * row.
 *
 * Note what it does not take: no Update. By the time this runs the
 * message that asked for it is long gone, so the chat id travels in the
 * job data instead.
 */
class SendTime implements JobInterface
{
    public function __construct(
        private Bot $bot,
        private ClockService $clock
    ) {
    }

    public static function name(): string
    {
        return 'send_time';
    }

    public function handle(JobContext $context): void
    {
        $chatId = $context->int('chat');

        if ($chatId === 0) {
            // Nothing to send to. Throwing would retry it three times and
            // fail; the data is simply wrong, so let the run succeed and
            // the job retire.
            return;
        }

        $zone = $context->string('zone', $this->clock->defaultZone());
        $note = $context->string('note');

        $text = ($note !== '' ? '<b>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</b>' . PHP_EOL : '')
            . $this->clock->text($zone)
            . PHP_EOL . '<i>run ' . $context->run . '</i>';

        $this->bot->sendMessage($text)
            ->to($chatId)
            ->parseMode('HTML')
            ->execute();
    }
}
