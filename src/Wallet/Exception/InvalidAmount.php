<?php

namespace Botex\Wallet\Exception;

/**
 * A zero, negative, or otherwise unusable input reached the service.
 *
 * Every mutating operation takes a strictly positive amount; direction
 * comes from the method called, never from the sign of the argument.
 */
class InvalidAmount extends WalletException
{
    public static function notPositive(int $amount): self
    {
        return new self(
            "Amount must be a positive integer in minor units, got {$amount}."
        );
    }

    public static function emptyReason(): self
    {
        return new self('Every balance change needs a reason.');
    }
}
