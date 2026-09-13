<?php

namespace Botex\Bot\Admin;

use Botex\Telegram\Update;

/**
 * Opt-in for sections whose own screens are worth a reply-keyboard label.
 *
 * The panel's keyboard normally carries one label per section, which is
 * enough to reach a section but not to work inside one: everything below
 * the section's front page is an inline button, and inline buttons scroll
 * away. A section that an admin lives in rather than visits -- servers,
 * plans, a service lookup -- says so here, and those screens become as
 * reachable as the section itself.
 *
 * Kept separate from AdminSectionInterface so a section that does not want
 * this needs no changes, which is every core section today.
 */
interface HasSubMenu
{
    /**
     * The screens worth a label, as label => screen key.
     *
     * Order is the order on the keyboard. Labels must be unique across the
     * whole panel: a reply-keyboard tap carries nothing but its text, so
     * two screens sharing a label would be the same button.
     *
     * @return array<string, string>
     */
    public static function menuItems(): array;

    /**
     * Opens one of them.
     *
     * The key is one of menuItems()'s values, already checked against that
     * list, so an unknown one means a label bound by an older version of
     * the extension: show the section's front page rather than nothing.
     */
    public function openScreen(Update $update, string $screen): void;
}
