<?php

namespace Botex\Wallet;

use Botex\Wallet\Exception\InvalidAmount;

/**
 * Generic pointer from a ledger entry to something outside the wallet.
 *
 * Deliberately a free-form type/id pair rather than a relation, so an
 * extension can link a transaction to its own order without the wallet
 * knowing that extension exists.
 */
class Reference
{
    private const MAX_LENGTH = 191;

    public function __construct(
        public readonly string $type,
        public readonly string $id
    ) {
        $this->guard('type', $type);
        $this->guard('id', $id);
    }

    public static function to(string $type, string|int $id): self
    {
        return new self($type, (string) $id);
    }

    public function equals(?self $other): bool
    {
        return $other !== null
            && $other->type === $this->type
            && $other->id === $this->id;
    }

    public function __toString(): string
    {
        return $this->type . '#' . $this->id;
    }

    private function guard(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidAmount("Reference {$field} cannot be empty.");
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidAmount(
                "Reference {$field} cannot exceed " . self::MAX_LENGTH . ' characters.'
            );
        }
    }
}
