<?php

namespace Botex\Wallet\Exception;

/**
 * Internal signal, never thrown to callers.
 *
 * Raised inside a wallet transaction when the unique idempotency key was
 * taken by a concurrent request. It unwinds the transaction so the balance
 * move is discarded, and WalletService catches it outside the transaction
 * to return the entry the winning request wrote.
 */
class DuplicateKeyRace extends WalletException
{
    public function __construct(
        public readonly string $key,
        ?\Throwable $previous = null
    ) {
        parent::__construct("Idempotency key \"{$key}\" was claimed concurrently.", 0, $previous);
    }
}
