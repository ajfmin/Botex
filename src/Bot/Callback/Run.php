<?php

namespace Botex\Bot\Callback;

use Botex\Bot\Action\ActionRunner;
use Botex\Bot\Action\ActionToken;
use Botex\Telegram\Update;

/**
 * Routes run:<token> to the action it was minted for.
 *
 * A thin owner of the prefix, the way OpenSection owns admin:s:. All the
 * work is ActionRunner's, so the same logic serves reply-keyboard presses
 * that never reach a callback at all.
 *
 * Deliberately no middleware here: a Run token can belong to any
 * extension, so the gate is whatever the resolved runnable declares.
 */
class Run implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private ActionRunner $runner
    ) {
    }

    public static function callback(): string
    {
        return ActionToken::PREFIX;
    }

    public static function matches(string $data): bool
    {
        return ActionToken::fromCallback($data) !== null;
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $this->runner->handleCallback($update);
    }
}
