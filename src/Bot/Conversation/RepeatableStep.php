<?php

namespace Botex\Bot\Conversation;

/**
 * Opt-in for a step that asks a variable number of questions.
 *
 * The step list is static, but some flows only learn how many questions
 * they have at runtime (an admin-defined set, for instance). A step
 * implementing this stays current until hasMore() says otherwise, and
 * owns where each answer is stored so successive answers do not
 * overwrite each other.
 */
interface RepeatableStep
{
    /** Records one answer. Called instead of the default session write. */
    public function store(Session $session, mixed $value): void;

    /** True to ask again, false to move to the next step. */
    public function hasMore(Session $session): bool;
}
