<?php

namespace Botex\Wallet\Exception;

/**
 * Refused to open a wallet for a user id that does not exist, which
 * would otherwise leave an orphaned balance nobody can reach.
 */
class WalletOwnerNotFound extends WalletException
{
    public function __construct(
        public readonly int $userId
    ) {
        parent::__construct("No user with id {$userId}.");
    }
}
