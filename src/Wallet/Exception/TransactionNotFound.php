<?php

namespace Botex\Wallet\Exception;

class TransactionNotFound extends WalletException
{
    public function __construct(
        public readonly int $transactionId
    ) {
        parent::__construct("No wallet transaction with id {$transactionId}.");
    }
}
