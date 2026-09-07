<?php

namespace Botex\Bot\Admin;

/**
 * The three user operations reachable from the Users panel, and the
 * callback data that starts each one.
 *
 * Each asks for a telegram id, so they share a single flow and differ
 * only in what they do with it.
 */
class UserAction
{
    public const PREFIX = 'admin:user:';

    public const CHECK = 'check';

    public const BLOCK = 'block';

    public const UNBLOCK = 'unblock';

    public static function start(string $action): string
    {
        return self::PREFIX . $action;
    }

    /** @return array<string> */
    public static function all(): array
    {
        return [self::CHECK, self::BLOCK, self::UNBLOCK];
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
}
