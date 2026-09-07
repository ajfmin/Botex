<?php

namespace Botex\Bot\Conversation;

/**
 * The outcome of a step reading one update: either a clean value or a
 * message explaining what the user got wrong.
 *
 * Steps never write to the session or send anything themselves; they
 * return one of these and let the runner act on it.
 */
class Answer
{
    private function __construct(
        public readonly bool $valid,
        public readonly mixed $value,
        public readonly string $error
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value, '');
    }

    public static function error(string $message): self
    {
        return new self(false, null, $message);
    }
}
