<?php

namespace Botex\Bot\Admin;

use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;

/**
 * Shared chrome for admin screens: the home menu and the back button
 * every section shows, so the panel stays navigable as extensions add
 * sections of their own.
 */
class Panel
{
    public const HOME = 'admin:home';

    public const SECTION = 'admin:s:';

    public function __construct(
        private Sections $sections
    ) {
    }

    public static function section(string $key): string
    {
        return self::SECTION . $key;
    }

    /** Two sections per row, in registration order. */
    public function menu(): array
    {
        $keyboard = Keyboard::inline();
        $row = [];

        foreach ($this->sections->all() as $key => $class) {
            $row[] = InlineButton::callback($class::title(), self::section($key));

            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        return $keyboard->build();
    }

    public static function backKeyboard(): array
    {
        return Keyboard::inline()
            ->row(InlineButton::callback('Back', self::HOME))
            ->build();
    }
}
