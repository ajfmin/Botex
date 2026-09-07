<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\Flow\ManageUserFlow;
use Botex\Bot\Admin\UserAction;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Starts the manage-user flow, seeding which action to perform once the
 * id arrives.
 */
class StartUserAction implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private Bot $bot,
        private FlowRunner $runner
    ) {
    }

    public static function callback(): string
    {
        return UserAction::PREFIX;
    }

    public static function matches(string $data): bool
    {
        return UserAction::fromCallback($data) !== null;
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

        $action = UserAction::fromCallback((string) $update->callbackData());

        if ($action === null) {
            return;
        }

        $this->runner->start(
            ManageUserFlow::name(),
            $update,
            ['action' => $action]
        );
    }
}
