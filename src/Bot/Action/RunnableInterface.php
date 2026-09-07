<?php

namespace Botex\Bot\Action;

use Botex\Telegram\Update;

/**
 * One named action an extension can be asked to run.
 *
 * Implementations are resolved through Feeder, so constructor
 * dependencies are injected the same way commands and callbacks get
 * theirs. Extensions publish them from their runnables() hook.
 */
interface RunnableInterface
{
    /**
     * The stable name stored with the action.
     *
     * Renaming this orphans buttons already sent, which then degrade to
     * the "no longer available" notice.
     */
    public static function name(): string;

    /**
     * Middleware classes checked before handle(), on top of whatever the
     * entry point already applies.
     *
     * @return array<class-string>
     */
    public static function middleware(): array;

    public function handle(Update $update, RunContext $context): void;
}
