<?php

namespace Botex\Bot\Admin;

use Botex\Telegram\Update;

/**
 * A panel inside /admin.
 *
 * Core sections and extension sections use the same contract, so an
 * extension's panel is indistinguishable from a built-in one. Reaching
 * any section goes through the admin gate, so a section never has to
 * check permissions itself.
 */
interface AdminSectionInterface
{
    /**
     * Stable key, used as the callback data suffix (admin:s:<key>).
     * Must contain no colons.
     */
    public static function key(): string;

    /** Button label on the admin home screen. */
    public static function title(): string;

    /** Renders the panel. */
    public function handle(Update $update): void;
}
