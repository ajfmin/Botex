<?php

namespace Botex\Bot\Callback;

/**
 * Opt-in for callbacks that own a family of callback data rather than
 * one exact string, e.g. admin:s:<key> or feedback:rate:<n>.
 *
 * Kept separate from CallbackInterface so plain callbacks stay a single
 * constant and existing ones did not need changing.
 */
interface MatchesCallback
{
    public static function matches(string $data): bool;
}
