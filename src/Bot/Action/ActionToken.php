<?php

namespace Botex\Bot\Action;

/**
 * The opaque handle to a stored action.
 *
 * This is all that travels to Telegram: no class name, no slug, no
 * payload. Inline buttons carry it as callback data behind the run:
 * prefix, the way Panel::SECTION prefixes its own family. Reply
 * keyboards carry nothing at all and resolve by label instead, so a
 * token for them exists only to key the stored row.
 */
class ActionToken
{
    /** Callback-data prefix that Callback\Run owns. */
    public const PREFIX = 'run:';

    /** Bytes of randomness; 12 gives 96 bits in 24 hex characters. */
    private const BYTES = 12;

    public const INLINE = 'inline';
    public const REPLY = 'reply';

    public function __construct(
        public readonly string $value,
        public readonly string $kind = self::INLINE
    ) {
    }

    public static function mint(string $kind = self::INLINE): self
    {
        return new self(bin2hex(random_bytes(self::BYTES)), $kind);
    }

    /**
     * Pulls a token out of callback data, or null when the data belongs
     * to some other callback family.
     *
     * Shape is checked here so a hand-crafted callback never reaches the
     * store as a wildcard lookup.
     */
    public static function fromCallback(?string $data): ?self
    {
        if ($data === null || !str_starts_with($data, self::PREFIX)) {
            return null;
        }

        $value = substr($data, strlen(self::PREFIX));

        return preg_match('/^[0-9a-f]{' . (self::BYTES * 2) . '}$/', $value)
            ? new self($value, self::INLINE)
            : null;
    }

    /** What goes in callback_data. Well inside Telegram's 64-byte limit. */
    public function callbackData(): string
    {
        return self::PREFIX . $this->value;
    }

    public function isInline(): bool
    {
        return $this->kind === self::INLINE;
    }

    public function __toString(): string
    {
        return $this->callbackData();
    }
}
