<?php

namespace Botex\Bot;

use Botex\Bot\Action\ActionRunner;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Command\Commands;
use Botex\Bot\Conversation\FlowRunner;
use Botex\Extension\Registry;
use Botex\Telegram\Update;

class Router
{
    /** @var array<class-string> */
    private const CALLBACKS = [
        Callback\Admin\Home::class,
        Callback\Admin\OpenSection::class,
        Callback\Admin\StartUserAction::class,
        Callback\Admin\StartWalletAction::class,
        Callback\Run::class,
    ];

    public function __construct(
        private Dispatcher $dispatcher,
        private Registry $extensions,
        private FlowRunner $flows,
        private ActionRunner $actions,
        private Commands $commands,
        private CurrentUpdate $current
    ) {
    }

    public function handle(Update $update): void
    {
        // Recorded before anything routes, so a menu button built deep in a
        // handler can scope its label to whoever this update came from
        // without every call site passing the audience down.
        $this->current->set($update);

        if ($update->isMessage()) {
            $this->handleMessage($update);
            return;
        }

        if ($update->isCallback()) {
            $this->handleCallback($update);
            return;
        }
    }

    public function handleMessage(Update $update): void
    {
        $text = $update->text();

        // A slash command always wins, so /cancel and /start still work
        // from inside a flow. Anything else is treated as an answer,
        // which keeps a free-text step from being hijacked by a reply
        // that happens to match a button label.
        if (!$this->isCommand($text) && $this->flows->handle($update)) {
            return;
        }

        $command = $this->matchCommand($text);

        // A reply-keyboard label bound to a Run action, but only where no
        // command claims that label. Asked here rather than earlier so a
        // core button can never be shadowed by a stored binding.
        if ($command === Command\Unknown::class && $this->actions->handleText($update)) {
            return;
        }

        $this->dispatcher->dispatch($command, $update);
    }

    public function handleCallback(Update $update): void
    {
        $data = $update->callbackData();

        // A registered callback wins over the active flow: buttons like
        // the admin menu must stay usable mid-conversation. Choice steps
        // are matched by the flow because their data is not registered.
        if ($this->findCallback($data) === null && $this->flows->handle($update)) {
            return;
        }

        $this->dispatcher->dispatch(
            $this->matchCallback($data),
            $update
        );
    }

    private function isCommand(?string $text): bool
    {
        return $text !== null && str_starts_with(trim($text), '/');
    }

    /**
     * Matches on the command itself (/start) or on the menu button
     * label that stands in for it.
     *
     * @return class-string
     */
    private function matchCommand(?string $text): string
    {
        if ($text === null) {
            return Command\Unknown::class;
        }

        $text = trim($text);

        // The command is matched on its first word, so "/remind 30" reaches
        // the same handler as "/remind" and the handler reads its own
        // arguments. Resolved through the same allowlist a Run button uses,
        // so typing and pressing can never disagree about what a verb means.
        // Only for text that is actually a command: the allowlist is keyed
        // by bare verb, so plain "start" must not reach /start.
        if ($this->isCommand($text)) {
            $command = $this->commands->find($this->verb($text));

            if ($command !== null) {
                return $command;
            }
        }

        // A button label is matched whole, since labels are free text and
        // often contain spaces.
        foreach ($this->commands->all() as $class) {
            if ($text === $class::button()) {
                return $class;
            }
        }

        return Command\Unknown::class;
    }

    /**
     * The command word on its own, with any @botname suffix removed.
     *
     * Telegram appends that suffix in groups, so "/start@MyBot" has to
     * resolve the same way "/start" does.
     */
    private function verb(string $text): string
    {
        $first = strtok($text, " \t\n") ?: '';

        if (!str_starts_with($first, '/')) {
            return $first;
        }

        $at = strpos($first, '@');

        return $at === false ? $first : substr($first, 0, $at);
    }

    /** @return class-string */
    private function matchCallback(?string $data): string
    {
        return $this->findCallback($data) ?? Callback\Unknown::class;
    }

    /**
     * Exact matches first, then prefix owners, so a callback declaring a
     * family (admin:s:) can never shadow an exact one inside it.
     *
     * @return class-string|null
     */
    private function findCallback(?string $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $callbacks = $this->callbacks();

        foreach ($callbacks as $callback) {
            if ($data === $callback::callback()) {
                return $callback;
            }
        }

        foreach ($callbacks as $callback) {
            if (is_subclass_of($callback, MatchesCallback::class) && $callback::matches($data)) {
                return $callback;
            }
        }

        return null;
    }

    /** @return array<class-string> */
    private function callbacks(): array
    {
        return [...self::CALLBACKS, ...$this->extensions->callbacks()];
    }
}
