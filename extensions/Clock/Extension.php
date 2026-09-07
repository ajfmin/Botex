<?php

namespace Extensions\Clock;

use Botex\Extension\AbstractExtension;
use Extensions\Clock\Action\ShowTime;
use Extensions\Clock\Callback\Refresh;
use Extensions\Clock\Command\Clock;
use Extensions\Clock\Command\Remind;
use Extensions\Clock\Command\Watch;
use Extensions\Clock\Command\Zones;
use Extensions\Clock\Job\SendTime;

/**
 * Extends AbstractExtension, so hooks it does not use (flows, admin
 * sections) fall back to empty defaults.
 */
class Extension extends AbstractExtension
{
    public static function commands(): array
    {
        return [
            Clock::class,
            Zones::class,
            Remind::class,
            Watch::class,
        ];
    }

    public static function callbacks(): array
    {
        return [
            Refresh::class,
        ];
    }

    /**
     * What a Run button may be bound to. The allowlist resolves
     * "Clock:show_time" to ShowTime through this hook, so nothing about
     * the class ever travels through Telegram.
     */
    public static function runnables(): array
    {
        return [
            ShowTime::class,
        ];
    }

    /**
     * Work the worker may run on this extension's behalf. Resolved as
     * "Clock:send_time" through the same allowlist, so disabling this
     * extension stops its scheduled work without cancelling anything.
     */
    public static function jobs(): array
    {
        return [
            SendTime::class,
        ];
    }
}
