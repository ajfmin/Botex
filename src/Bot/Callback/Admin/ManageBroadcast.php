<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\BroadcastAction;
use Botex\Bot\Admin\Flow\BroadcastFlow;
use Botex\Bot\Admin\Section\Broadcast as Section;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Bot\Feeder;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Broadcast\BroadcastService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * New, Pause, Resume, Stop and Refresh on the Broadcast screen.
 *
 * Each one acts on what is already on screen, so the screen is redrawn
 * in place afterwards with a line saying what changed. An admin watching
 * a send should end up with one message that keeps updating, not a
 * column of near-identical ones.
 *
 * Every transition is attempted rather than assumed: the buttons were
 * drawn at some point in the past and the broadcast has been moving ever
 * since, so "Pause" pressed on a send that finished two seconds ago is
 * answered honestly instead of reviving anything. That check lives in
 * the repository, as one conditional UPDATE, which is also what makes it
 * safe against two admins pressing at once.
 */
class ManageBroadcast implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private Bot $bot,
        private BroadcastService $broadcasts,
        private FlowRunner $runner,
        private Feeder $feeder
    ) {
    }

    public static function callback(): string
    {
        return BroadcastAction::PREFIX;
    }

    public static function matches(string $data): bool
    {
        return BroadcastAction::parse($data) !== null;
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function handle(Update $update): void
    {
        $parsed = BroadcastAction::parse((string) $update->callbackData());
        $callbackId = $update->callbackId();

        if ($parsed === null) {
            if ($callbackId !== null) {
                $this->bot->answerCallback($callbackId);
            }

            return;
        }

        [$action, $id] = $parsed;

        if ($action === BroadcastAction::NEW) {
            if ($callbackId !== null) {
                $this->bot->answerCallback($callbackId);
            }

            $this->runner->start(BroadcastFlow::name(), $update);

            return;
        }

        $notice = $this->apply($action, $id);

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId, $notice);
        }

        $this->feeder->make(Section::class)->show(
            $update,
            $notice === '' ? '' : htmlspecialchars($notice, ENT_QUOTES, 'UTF-8')
        );
    }

    /** @return string what to say about it, or '' for a silent redraw */
    private function apply(string $action, int $id): string
    {
        if ($id < 1) {
            return '';
        }

        return match ($action) {
            BroadcastAction::PAUSE => $this->broadcasts->pause($id)
                ? '⏸ Paused. Nothing more goes out until you resume it.'
                : 'That broadcast is not running any more.',
            BroadcastAction::RESUME => $this->broadcasts->resume($id)
                ? '▶️ Resumed from where it stopped.'
                : 'That broadcast cannot be resumed.',
            BroadcastAction::STOP => $this->broadcasts->cancel($id)
                ? '✖️ Stopped. What had already been delivered stays delivered.'
                : 'That broadcast has already finished.',
            default => '',
        };
    }
}
