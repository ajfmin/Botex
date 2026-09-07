<?php

namespace Botex\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Transaction boundary helper.
 *
 * Eloquent nests transactions with savepoints, so calling this inside an
 * existing transaction joins that one rather than starting a second.
 * That is what lets a wallet operation stand alone and also participate
 * in a larger business operation without either knowing about the other.
 */
class Db
{
    /**
     * Runs the callback in a transaction, committing on return and
     * rolling back on any throwable.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return Capsule::connection()->transaction($callback);
    }

    public static function inTransaction(): bool
    {
        return Capsule::connection()->transactionLevel() > 0;
    }

    public static function level(): int
    {
        return Capsule::connection()->transactionLevel();
    }
}
