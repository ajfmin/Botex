<?php

namespace Botex\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Thin wrapper over Eloquent's schema builder.
 *
 * Every method is idempotent so an extension's install() stays safe to
 * run twice, which the ExtensionInterface contract requires.
 */
class Schema
{
    public static function createIfMissing(string $table, callable $definition): bool
    {
        if (self::hasTable($table)) {
            return false;
        }

        Capsule::schema()->create($table, function (Blueprint $blueprint) use ($definition) {
            $definition($blueprint);
        });

        return true;
    }

    public static function dropIfExists(string $table): void
    {
        Capsule::schema()->dropIfExists($table);
    }

    public static function hasTable(string $table): bool
    {
        return Capsule::schema()->hasTable($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return Capsule::schema()->hasColumn($table, $column);
    }

    /** Adds columns that are not there yet, leaving existing ones alone. */
    public static function addMissing(string $table, callable $definition): void
    {
        Capsule::schema()->table($table, function (Blueprint $blueprint) use ($definition) {
            $definition($blueprint);
        });
    }
}
