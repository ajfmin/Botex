<?php

namespace Botex\Bot\Admin;

/**
 * The two balance adjustments reachable from the Wallet panel, and the
 * callback data that starts each one.
 *
 * Both ask for the same answers, so they share a single flow and differ
 * only in the direction the money moves.
 */
class WalletAction
{
    public const PREFIX = 'admin:wallet:';

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public static function start(string $action): string
    {
        return self::PREFIX . $action;
    }

    /** @return array<string> */
    public static function all(): array
    {
        return [self::CREDIT, self::DEBIT];
    }

    public static function valid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }

    /** @return string|null the action, or null if the data is not ours */
    public static function fromCallback(string $data): ?string
    {
        if (!str_starts_with($data, self::PREFIX)) {
            return null;
        }

        $action = substr($data, strlen(self::PREFIX));

        return self::valid($action) ? $action : null;
    }

    /** Button and heading wording. */
    public static function title(string $action): string
    {
        return $action === self::DEBIT ? 'Debit' : 'Credit';
    }

    /** Reads as "credit 500 to" or "debit 500 from" a balance. */
    public static function preposition(string $action): string
    {
        return $action === self::DEBIT ? 'from' : 'to';
    }

    /** Wording for the result line, once the entry is written. */
    public static function past(string $action): string
    {
        return $action === self::DEBIT ? 'Debited' : 'Credited';
    }
}
