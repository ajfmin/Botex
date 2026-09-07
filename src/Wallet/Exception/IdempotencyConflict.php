<?php

namespace Botex\Wallet\Exception;

/**
 * An idempotency key was reused with different financial parameters.
 *
 * Returning the original result would hide a caller bug, so this is an
 * error rather than a silent no-op. A genuine retry sends the same type
 * and amount and gets the original transaction back.
 */
class IdempotencyConflict extends WalletException
{
    public function __construct(
        public readonly string $key,
        string $detail
    ) {
        parent::__construct("Idempotency key '{$key}' was reused with {$detail}.");
    }
}
