<?php

namespace Botex\Bot\Admin\Flow;

use Botex\Bot\Admin\Flow\Step\AskAnnouncement;
use Botex\Bot\Admin\Flow\Step\ConfirmAnnouncement;
use Botex\Bot\Conversation\FlowInterface;
use Botex\Bot\Conversation\Session;
use Botex\Broadcast\BroadcastException;
use Botex\Broadcast\BroadcastService;
use Botex\Telegram\Bot;

/**
 * Takes an announcement from an admin and queues it.
 *
 * Two steps and no more: the message, and one confirmation. Everything
 * else an admin might want to decide -- who gets it, how fast, when to
 * stop -- is either not a choice (the audience is everyone the bot can
 * reach) or better made while it is running, on the screen that shows
 * how it is going.
 *
 * complete() queues and returns. Nothing is delivered on this path, so
 * the admin gets an answer in the time one database insert takes rather
 * than watching a spinner while ten thousand messages go out.
 */
class BroadcastFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private BroadcastService $broadcasts
    ) {
    }

    public static function name(): string
    {
        return 'admin.broadcast';
    }

    public static function steps(): array
    {
        return [
            AskAnnouncement::class,
            ConfirmAnnouncement::class,
        ];
    }

    public function complete(Session $session): void
    {
        $source = $session->get(AskAnnouncement::name());
        $quiet = $session->get(ConfirmAnnouncement::name()) === ConfirmAnnouncement::QUIET;

        if (!is_array($source)) {
            return;
        }

        $this->bot->sendMessage($this->queue($session, $source, $quiet))
            ->to($session->telegramId)
            ->parseMode('HTML');
    }

    /**
     * @param array{chat:int, message:int} $source
     */
    private function queue(Session $session, array $source, bool $quiet): string
    {
        try {
            $broadcast = $this->broadcasts->queueCopy(
                createdBy: $session->telegramId,
                fromChatId: (int) $source['chat'],
                messageId: (int) $source['message'],
                silent: $quiet
            );
        } catch (BroadcastException $e) {
            // Always something the admin can act on, so it is shown as
            // written rather than turned into "something went wrong".
            return '⚠️ ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        return implode(PHP_EOL, [
            '📣 <b>Broadcast #' . (int) $broadcast->id . ' queued.</b>',
            '',
            'It starts within a few seconds and takes about '
                . $this->broadcasts->duration($this->broadcasts->estimate()) . '.',
            'You will get the report here when it finishes.',
            '',
            '<i>The worker has to be running for it to go anywhere: '
                . 'php bin/console jobs:work</i>',
        ]);
    }
}
