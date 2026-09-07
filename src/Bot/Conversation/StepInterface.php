<?php

namespace Botex\Bot\Conversation;

use Botex\Telegram\Update;

/**
 * One question in a flow.
 *
 * A step is pure with respect to the conversation: it renders a prompt
 * and validates an update. It does not send messages, write the session,
 * or decide what comes next. The runner does those.
 */
interface StepInterface
{
    /** Stable key. Stored in the session and used to route back here. */
    public static function name(): string;

    /** What to ask. */
    public function prompt(Session $session): Prompt;

    /**
     * Reads the user's reply.
     *
     * Return Answer::error() to re-ask with a message; the runner keeps
     * the user on this step. Return Answer::ok() with the value to store
     * under name().
     */
    public function validate(Update $update, Session $session): Answer;
}
