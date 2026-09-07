<?php

namespace Botex\Wallet\Exception;

/**
 * A debit was refused because the balance would have gone negative.
 *
 * Carries the numbers so a caller can tell the user how short they are
 * without a second query.
 */
class InsufficientFunds extends WalletException
{
    public function __construct(
        public readonly int $balance,
        public readonly int $requested
    ) {
        parent::__construct(
            "Insufficient funds: balance {$balance}, requested {$requested}."
        );
    }

    public function shortfall(): int
    {
        return max(0, $this->requested - $this->balance);
    }
}
