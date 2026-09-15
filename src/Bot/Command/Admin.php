<?php

namespace Botex\Bot\Command;

use Botex\Bot\Admin\Panel;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Update;

class Admin implements CommandInterface
{
    public function __construct(
        private Panel $panel
    ) {
    }

    public static function command(): string
    {
        return '/admin';
    }

    public static function button(): string
    {
        return 'Admin';
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    /**
     * The keyboard, then the screen.
     *
     * Two messages because Telegram allows one reply_markup each, and
     * the keyboard is the navigation while the screen is what gets read.
     * The screen carries no buttons of its own: the sections are on the
     * keyboard, and listing them again underneath is the duplication
     * this panel used to ship with.
     *
     * Forced, unlike every other caller: typing /admin is what an admin
     * does after closing the keyboard, and the whole point of it is to
     * bring the keyboard back.
     */
    public function handle(Update $update): void
    {
        $this->panel->useMenu($update, null, force: true);

        $this->panel->show($update, $this->panel->homeText());
    }
}
