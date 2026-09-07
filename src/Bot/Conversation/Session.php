<?php

namespace Botex\Bot\Conversation;

/**
 * A user's position in a flow, plus the answers collected so far.
 *
 * The flow is held as its registered name rather than a class name, so
 * nothing resolved out of the database is ever instantiated directly.
 */
class Session
{
    public function __construct(
        public readonly int $telegramId,
        public readonly string $flow,
        public string $step,
        private array $data = []
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
