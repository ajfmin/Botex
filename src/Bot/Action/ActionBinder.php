<?php

namespace Botex\Bot\Action;

use Botex\Bot\Command\Commands;
use Botex\Bot\CurrentUpdate;
use Botex\Support\Config;
use Botex\Telegram\Update;

/**
 * Persists a Run intent and hands back the token that travels.
 *
 * Binding happens at render time, because that is the last moment the
 * intent still exists in full. Everything downstream, the keyboard,
 * Telegram, the incoming press, only ever sees a token or a label.
 *
 * Buttons call in here themselves when a keyboard is built, so a caller
 * writes ->action(Run::...) and never touches the binder. It stays public
 * for the cases a button shape does not cover.
 */
class ActionBinder
{
    public function __construct(
        private ActionStore $store,
        private Runnables $runnables,
        private Commands $commands,
        private CurrentUpdate $current,
        private Config $config
    ) {
    }

    /**
     * Binds an inline press and returns its callback data.
     *
     * The audience is optional: an inline token identifies the row on its
     * own, so scoping it to a user is a courtesy rather than the mechanism.
     */
    public function inline(Run $run, Update|int|null $audience = null): string
    {
        return $this->bind(
            $run,
            ActionToken::INLINE,
            $this->telegramId($audience) ?? $this->current->telegramId(),
            null
        )->callbackData();
    }

    /**
     * Binds a reply-keyboard label for one user.
     *
     * Required here, not optional: a label carries no token back, so the
     * binding only means anything scoped to one user. Any earlier binding
     * for the same label is dropped, since a new reply keyboard replaces
     * the old one on screen.
     */
    public function menu(Run $run, string $label, Update|int|null $audience = null): void
    {
        $telegramId = $this->telegramId($audience) ?? $this->current->telegramId();

        if ($telegramId === null) {
            throw new \InvalidArgumentException(
                'A menu action needs a telegram id to scope its label to. '
                . 'There is no update being handled here, so name the audience: '
                . "->action(\$run)->audience(\$chatId) for '{$label}'."
            );
        }

        $this->store->forgetLabel($telegramId, $label);
        $this->bind($run, ActionToken::REPLY, $telegramId, $label);
    }

    /**
     * Persists a run and returns its token.
     *
     * For callers that need the token itself, e.g. to attach it to a button
     * shape the builders do not cover yet.
     */
    public function bind(
        Run $run,
        string $kind = ActionToken::INLINE,
        ?int $telegramId = null,
        ?string $label = null
    ): ActionToken {
        // Fail where the button is built rather than when it is pressed. A
        // disabled extension is not an error here: binding for it is
        // pointless but harmless, and the runner degrades gracefully.
        $this->guard($run);

        $token = ActionToken::mint($kind);

        $this->store->put($token, $run, $telegramId, $label, $run->ttl ?? $this->ttl());

        return $token;
    }

    /** Default ttl in seconds, from config. */
    public function ttl(): int
    {
        return max(0, (int) $this->config->get('actions.ttl', 604800));
    }

    /**
     * Prunes expired rows now and then.
     *
     * Sampled rather than scheduled so the table stays bounded without a
     * cron entry. Called by ActionRunner on the way past.
     */
    public function maybePrune(): int
    {
        $chance = (int) $this->config->get('actions.prune_chance', 200);

        if ($chance <= 0 || random_int(1, $chance) !== 1) {
            return 0;
        }

        return $this->store->prune();
    }

    /**
     * Rejects a target that does not exist, at build time.
     *
     * Both allowlists are the same ones the press path resolves against, so
     * a typo cannot survive to become a dead button.
     */
    private function guard(Run $run): void
    {
        if ($run->isCommand()) {
            if (!$this->commands->exists($run->name)) {
                throw UnknownAction::command($run->name);
            }

            return;
        }

        if (!$this->runnables->exists($run->extension, $run->name)) {
            throw new UnknownAction($run->extension, $run->name);
        }
    }

    private function telegramId(Update|int|null $audience): ?int
    {
        if ($audience instanceof Update) {
            $id = $audience->fromId();

            return $id === null ? null : (int) $id;
        }

        return $audience;
    }
}
