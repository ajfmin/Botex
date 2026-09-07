<?php

namespace Botex\Bot\Conversation;

/**
 * A multi-step conversation.
 *
 * Extensions implement this to collect data across several messages.
 * The step list is static so the runner can inspect a flow's shape
 * without constructing it.
 */
interface FlowInterface
{
    /**
     * Stable name. This is what gets written to the database, and it is
     * resolved back to a class only through the Flows registry.
     */
    public static function name(): string;

    /**
     * Ordered step classes.
     *
     * @return array<class-string<StepInterface>>
     */
    public static function steps(): array;

    /**
     * Called once every step has a valid answer.
     *
     * Runs with the flow already cleared, so an exception here cannot
     * leave the user trapped in a finished conversation.
     */
    public function complete(Session $session): void;
}
