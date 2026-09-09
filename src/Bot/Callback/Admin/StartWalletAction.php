<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\Flow\AdjustBalanceFlow;
use Botex\Bot\Admin\WalletAction;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Starts the adjust-balance flow, seeding which direction the money
 * moves once the answers arrive.
 */
class StartWalletAction implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private Bot $bot,
        private FlowRunner $runner
    ) {
    }

    public static function callback(): string
    {
        return WalletAction::PREFIX;
    }

    public static function matches(string $data): bool
    {
        return WalletAction::fromCallback($data) !== null;
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

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId);
        }

        $action = WalletAction::fromCallback((string) $update->callbackData());

        if ($action === null) {
            return;
        }

        $this->runner->start(
            AdjustBalanceFlow::name(),
            $update,
            ['action' => $action]
        );
    }
}
