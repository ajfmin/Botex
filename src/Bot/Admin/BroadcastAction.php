<?php

namespace Botex\Bot\Admin;

/**
 * The management buttons on the Broadcast screen, and the callback data
 * behind each one.
 *
 * Everything here acts on a broadcast that is already on screen, which
 * is why they are inline buttons rather than keyboard labels: the
 * Broadcast screen itself is navigation and lives on the keyboard, and
 * Pause, Resume and Stop act on the thing shown in the message they sit
 * under. See Panel for the rule.
 *
 * A broadcast id rides in the data because a screen can outlive what it
 * described -- an admin scrolling back to yesterday's message must not
 * be able to pause today's send by pressing a button that meant
 * something else when it was drawn.
 */
class BroadcastAction
{
    public const PREFIX = 'admin:bc:';

    /** Starts the compose flow. Takes no id: there is nothing yet. */
    public const NEW = 'new';

    public const PAUSE = 'pause';

    public const RESUME = 'resume';

    public const STOP = 'stop';

    /** Re-renders the screen, for watching a send move. */
    public const REFRESH = 'refresh';

    public static function to(string $action, int $id = 0): string
    {
        return self::PREFIX . $action . ($id > 0 ? ':' . $id : '');
    }

    /** @return array<string> */
    public static function all(): array
    {
        return [self::NEW, self::PAUSE, self::RESUME, self::STOP, self::REFRESH];
    }

    /**
     * Splits callback data into an action and an id.
     *
     * @return array{0:string, 1:int}|null null when the data is not ours
     */
    public static function parse(string $data): ?array
    {
        if (!str_starts_with($data, self::PREFIX)) {
            return null;
        }

        $rest = substr($data, strlen(self::PREFIX));
        $parts = explode(':', $rest, 2);
        $action = $parts[0];

        if (!in_array($action, self::all(), true)) {
            return null;
        }

        $id = $parts[1] ?? '';

        // An id is either absent or digits. Anything else was not drawn
        // by a button of ours.
        if ($id !== '' && !ctype_digit($id)) {
            return null;
        }

        return [$action, $id === '' ? 0 : (int) $id];
    }
}
