<?php

namespace Botex\Bot\Action;

use Botex\Bot\Command\Commands;
use Botex\Bot\Dispatcher;
use Botex\Bot\Feeder;
use Botex\Extension\Registry;
use Botex\Model\StoredAction;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Resolves a button press back to its stored action and runs it.
 *
 * The same shape as FlowRunner: handle() reports whether it consumed the
 * update, so the router asks rather than knowing. Every side effect of a
 * press, acknowledging, stamping, explaining a dead action, happens here
 * so runnables stay as simple as steps are.
 */
class ActionRunner
{
    /** Shown when the row is gone, or its extension is. */
    public const STALE = 'This button is no longer available.';

    /** Shown when the ttl ran out. */
    public const EXPIRED = 'This button has expired. Please open the menu again.';

    /** Shown on a second press of a single-use button. */
    public const SPENT = 'That was already done.';

    /** Shown when a runnable throws. */
    public const FAILED = 'Something went wrong running that action.';

    public function __construct(
        private ActionStore $store,
        private Runnables $runnables,
        private Commands $commands,
        private ActionBinder $binder,
        private Registry $extensions,
        private Dispatcher $dispatcher,
        private Feeder $feeder,
        private Bot $bot,
        private Logger $log
    ) {
    }

    /**
     * Whether this update looks like an inline Run press.
     *
     * Cheap enough for the router to ask before doing anything else.
     */
    public function ownsCallback(Update $update): bool
    {
        return ActionToken::fromCallback($update->callbackData()) !== null;
    }

    /**
     * Handles an inline press, identified by its token.
     *
     * @return bool true if this was a Run token, whether or not it
     *              resolved; false to let the router carry on
     */
    public function handleCallback(Update $update): bool
    {
        $token = ActionToken::fromCallback($update->callbackData());

        if ($token === null) {
            return false;
        }

        $this->acknowledge($update);
        $this->execute($this->store->find($token), $update, $token);

        return true;
    }

    /**
     * Handles a reply-keyboard press, identified by its label.
     *
     * Only claims a label that is actually bound for this user, so plain
     * text and command labels fall through untouched. The router asks
     * this after commands, which is what keeps a core command from being
     * shadowed by a bound label.
     */
    public function handleText(Update $update): bool
    {
        $text = $update->text();
        $telegramId = $update->fromId();

        if ($text === null || $text === '' || $telegramId === null) {
            return false;
        }

        $row = $this->store->findByLabel((int) $telegramId, $text);

        if ($row === null) {
            return false;
        }

        $this->execute($row, $update, null);

        return true;
    }

    /**
     * Runs an action directly, bypassing a button.
     *
     * Lets one action chain into another, e.g. a confirm step handing off
     * to the action it confirms, without minting a throwaway button.
     */
    public function run(Run $run, Update $update): bool
    {
        if ($run->isCommand()) {
            return $this->runCommand($run->name, $run->commandText(), $update);
        }

        $class = $this->resolve($run->extension, $run->name);

        if ($class === null) {
            return false;
        }

        return $this->invoke(
            $class,
            new RunContext($run->extension, $run->name, $run->data),
            $update
        );
    }

    /**
     * The shared press path: validate the row, then run it.
     *
     * Order matters. State is checked before the allowlist so a user
     * pressing an expired button is told it expired, not that it
     * vanished.
     */
    private function execute(?StoredAction $row, Update $update, ?ActionToken $token): void
    {
        // Ride along on real traffic to keep the table bounded.
        $this->binder->maybePrune();

        if ($row === null) {
            $this->notify($update, self::STALE);

            return;
        }

        if ($row->isExpired()) {
            $this->notify($update, self::EXPIRED);

            return;
        }

        if ($row->isSpent()) {
            $this->notify($update, self::SPENT);

            return;
        }

        // A command row resolves through the command allowlist, a runnable
        // row through the runnable one. Both are checked before the claim,
        // so a dead button is reported rather than silently spent.
        $isCommand = $row->isCommand();

        $class = $isCommand
            ? $this->commands->find((string) $row->action)
            : $this->resolve((string) $row->extension, (string) $row->action);

        if ($class === null) {
            $this->notify($update, self::STALE);

            return;
        }

        // Claimed before running, so a handler that throws halfway cannot
        // be retried into a double charge: a once action is spent by the
        // attempt, not by its outcome. The claim is also the real guard
        // against a duplicate press, since the isSpent() check above can
        // be passed by two simultaneous presses at once.
        if (!$this->store->claim((int) $row->id)) {
            $this->notify($update, self::SPENT);

            return;
        }

        if ($isCommand) {
            $this->dispatchCommand($class, $row->commandText(), $update);

            return;
        }

        // Re-read so presses reflects this claim rather than the stale
        // count the row was loaded with.
        $this->invoke($class, RunContext::fromRow($row->refresh(), $token), $update);
    }

    /**
     * Runs a command by verb, for a caller that has no stored row.
     *
     * @return bool false when no such command is registered
     */
    private function runCommand(string $verb, string $text, Update $update): bool
    {
        $class = $this->commands->find($verb);

        if ($class === null) {
            return false;
        }

        return $this->dispatchCommand($class, $text, $update);
    }

    /**
     * Hands a command the update it would have received had the user typed
     * it.
     *
     * The text is rewritten rather than passed through, because a press
     * carries callback data, not a command line: a command reading its own
     * arguments has to find them where it always does. Middleware runs, so
     * a button cannot walk past a gate typing would have hit.
     *
     * @param class-string<\Botex\Bot\Command\CommandInterface> $class
     */
    private function dispatchCommand(string $class, string $text, Update $update): bool
    {
        if (!$this->dispatcher->guard($class, $update)) {
            return false;
        }

        try {
            $this->feeder->make($class)->handle($update->asCommand($text));
        } catch (\Throwable $e) {
            // Terminal: the exception is reported to the user as FAILED and
            // swallowed, so this is its only record.
            $this->log->exception($e, "Command {$text} failed", context: [
                'command' => $text,
                'handler' => $class,
            ]);

            $this->notify($update, self::FAILED);

            return false;
        }

        return true;
    }

    /**
     * Resolves an action through the allowlist.
     *
     * Returns null for anything not contributed by currently loaded,
     * enabled code: a removed extension, a disabled one, or an action
     * that was renamed. No extension-specific routing, because the
     * Registry already reports only what is enabled.
     *
     * @return class-string<RunnableInterface>|null
     */
    private function resolve(string $extension, string $action): ?string
    {
        if ($extension !== Run::CORE && !$this->extensions->isEnabled($extension)) {
            return null;
        }

        return $this->runnables->find($extension, $action);
    }

    /**
     * @param class-string<RunnableInterface> $class
     */
    private function invoke(string $class, RunContext $context, Update $update): bool
    {
        // Reuses Dispatcher's chain so a runnable can demand IsAdmin the
        // same way a command does.
        if (!$this->dispatcher->guard($class, $update)) {
            return false;
        }

        try {
            $this->feeder->make($class)->handle($update, $context);
        } catch (\Throwable $e) {
            // Terminal, as above: the user gets FAILED and the row is spent,
            // so nothing else will ever report this throw.
            $this->log->exception($e, "Action {$context->extension}:{$context->action} failed", context: [
                'extension' => $context->extension,
                'action' => $context->action,
            ]);

            $this->notify($update, self::FAILED);

            return false;
        }

        return true;
    }

    /** Stops the spinner on an inline press. */
    private function acknowledge(Update $update): void
    {
        $callbackId = $update->callbackId();

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId);
        }
    }

    /**
     * Tells the user why nothing happened.
     *
     * A message either way: the spinner is already cleared by the time we
     * know the action is dead, and a reply-keyboard press has no spinner
     * at all.
     */
    private function notify(Update $update, string $message): void
    {
        $chatId = $update->chatId();

        if ($chatId !== null) {
            $this->bot->sendMessage($message)->to($chatId);
        }
    }
}
