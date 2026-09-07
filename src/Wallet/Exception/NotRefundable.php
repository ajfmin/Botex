<?php

namespace Botex\Wallet\Exception;

use Botex\Wallet\TransactionType;

/**
 * Only a debit can be refunded. Refunding a credit would be a debit, and
 * refunding a refund would be a way to walk the ledger in circles.
 */
class NotRefundable extends WalletException
{
    public static function wrongType(int $transactionId, TransactionType $type): self
    {
        return new self(
            "Transaction {$transactionId} is a {$type->value} and cannot be refunded; "
            . 'only debits can.'
        );
    }

    public static function exceedsOriginal(int $transactionId, int $remaining, int $requested): self
    {
        return new self(
            "Refunding {$requested} would exceed transaction {$transactionId}; "
            . "only {$remaining} remains refundable."
        );
    }

    public static function alreadyRefunded(int $transactionId): self
    {
        return new self("Transaction {$transactionId} is already fully refunded.");
    }
}
