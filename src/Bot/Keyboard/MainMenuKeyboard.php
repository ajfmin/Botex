<?php

namespace Botex\Bot\Keyboard;

use Botex\Telegram\Builders\Keyboard\{Keyboard, InlineButton, MenuButton};

class MainMenuKeyboard
{

    public static function make(): array
    {
        // Labels have to match a command's button(), which is how the
        // router maps a tap back to a handler.
        return keyboard::menu()
            ->row(
                MenuButton::make(\Botex\Bot\Command\Wallet::button())
                    ->style('primary')
            )
            ->resize()
            ->build();
    }


}

?>