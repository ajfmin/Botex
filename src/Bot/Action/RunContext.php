<?php

namespace Botex\Bot\Action;

use Botex\Model\StoredAction;

/**
 * The resolved action handed to a runnable.
 *
 * Everything here comes from the stored row, never from the update, so a
 * runnable can trust it: the token proved the press, the row holds what
 * the button was built to do.
 */
class RunContext
{
    /**
     * @param array<string, mixed> $data
     * @param int                  $presses how many times this action has
     *                                      been run, including this one
     */
    public function __construct(
        public readonly string $extension,
        public readonly string $action,
        public readonly array $data,
        public readonly int $presses = 1,
        public readonly ?string $label = null,
        public readonly ?ActionToken $token = null
    ) {
    }

    public static function fromRow(StoredAction $row, ?ActionToken $token = null): self
    {
        return new self(
            (string) $row->extension,
            (string) $row->action,
            is_array($row->data) ? $row->data : [],
            (int) $row->presses,
            $row->label === null ? null : (string) $row->label,
            $token
        );
    }

    /**
     * Reads a value out of the structured data, dot-notated for nesting.
     *
     * Same access style as Config, so nested action data reads the way
     * nested settings already do.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $sentinel = new \stdClass()) !== $sentinel;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * True on the first press.
     *
     * Useful for a reusable button that should only report "done" once.
     */
    public function isFirstPress(): bool
    {
        return $this->presses <= 1;
    }

    /** True when the press came from a reply-keyboard label. */
    public function isReply(): bool
    {
        return $this->token === null || !$this->token->isInline();
    }
}
