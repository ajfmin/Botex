<?php

namespace Botex\Wallet;

/**
 * What a ledger entry represents.
 *
 * The sign of the stored amount follows from this: credits and refunds
 * are positive, debits negative.
 */
enum TransactionType: string
{
    case CREDIT = 'credit';

    case DEBIT = 'debit';

    case REFUND = 'refund';

    /** True when this type increases the balance. */
    public function isIncoming(): bool
    {
        return $this !== self::DEBIT;
    }

    /** Converts a positive amount into the signed value to store. */
    public function signed(int $amount): int
    {
        return $this->isIncoming() ? $amount : -$amount;
    }
}
