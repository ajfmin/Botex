<?php

namespace Botex\Bot\Admin;

use Botex\Bot\Action\Run;
use Botex\Bot\Admin\Action\CloseMenu;
use Botex\Bot\Admin\Action\OpenSection;
use Botex\Telegram\Bot;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Builders\Keyboard\MenuButton;
use Botex\Telegram\Update;

/**
 * Shared chrome for admin screens: the home menu, the back button every
 * section shows, and the rule for how a screen is put on the phone.
 *
 * The panel is navigable two ways, because the two are good at different
 * things. **Inline** buttons sit in the message and edit it in place, so
 * moving through the panel leaves one message behind instead of a wall of
 * them. The **reply keyboard** sits under the text box and survives
 * everything: after a flow, after a customer's message, after scrolling
 * away, the sections are still one tap down rather than a /admin away.
 *
 * Both open the same sections through the same admin gate. The difference
 * is only how the section is reached, which is why show() exists: an
 * inline press has a message of ours to edit, a keyboard tap does not.
 */
class Panel
{
    public const HOME = 'admin:home';

    public const SECTION = 'admin:s:';

    /** Label that puts the panel back on screen from the keyboard. */
    public const HOME_LABEL = '🏠 Admin';

    /** Label that takes the keyboard away again. */
    public const CLOSE_LABEL = '✖️ Close menu';

    public function __construct(
        private Bot $bot,
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

    /**
     * The same sections as a reply keyboard, under the text box.
     *
     * A reply-keyboard label carries nothing back on the wire, so each one
     * is bound to a Run action scoped to this admin -- the mechanism menu
     * buttons already use. The bindings never expire: this keyboard stays
     * on screen until it is closed, and a label that stopped working after
     * an hour would be worse than no keyboard at all.
     *
     * Built only for whoever is being handled right now, which is the
     * admin who typed /admin.
     */
    public function menuKeyboard(): array
    {
        $keyboard = Keyboard::menu()->resize();
        $row = [];

        foreach ($this->sections->all() as $key => $class) {
            $row[] = MenuButton::make($class::title())->action(
                Run::core(OpenSection::name(), ['key' => $key])->never()
            );

            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        return $keyboard
            ->row(
                MenuButton::make(self::HOME_LABEL)->action(Run::core(OpenSection::name())->never()),
                MenuButton::make(self::CLOSE_LABEL)->action(Run::core(CloseMenu::name())->never())
            )
            ->build();
    }

    /** Takes the reply keyboard away, without touching anything else. */
    public static function hideKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    public static function backKeyboard(): array
    {
        return Keyboard::inline()
            ->row(InlineButton::callback('Back', self::HOME))
            ->build();
    }

    /**
     * Puts a screen in front of an admin.
     *
     * Edits in place when the update is an inline press, because that is a
     * message of ours already on screen and replacing it would push the
     * conversation down for nothing. Sends a new one otherwise: a keyboard
     * tap or a command arrives as the admin's own message, and editing
     * *that* is something Telegram refuses -- which is exactly how a panel
     * reached from the reply keyboard used to render as silence.
     *
     * @param array<mixed> $keyboard
     */
    public function show(Update $update, string $text, array $keyboard = []): void
    {
        if ($update->isCallback() && $update->messageId() !== null) {
            $message = $this->bot->editMessage($text, (int) $update->messageId());
        } else {
            $message = $this->bot->sendMessage($text);
        }

        $message->to($update->chatId())->parseMode('HTML');

        if ($keyboard !== []) {
            $message->replyMarkup($keyboard);
        }
    }
}
