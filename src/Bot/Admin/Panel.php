<?php

namespace Botex\Bot\Admin;

use Botex\Bot\Action\ActionStore;
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
 *
 * The keyboard follows the admin into a section that asked for it (see
 * [[HasSubMenu]]), so the screens inside it are one tap away rather than
 * a scroll back up to an inline button. Exactly one panel keyboard is
 * ever bound for an admin, which is what lets useMenu() tell whether the
 * keyboard already on their phone is the right one.
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
        private Sections $sections,
        private ActionStore $actions
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
     * The panel as a reply keyboard, under the text box.
     *
     * With no section, the sections themselves. With one, that section's
     * own screens instead, plus a way back up -- so an admin working
     * inside a section has its screens under their thumb rather than the
     * five other sections they are not using.
     *
     * A reply-keyboard label carries nothing back on the wire, so each one
     * is bound to a Run action scoped to this admin -- the mechanism menu
     * buttons already use. The bindings never expire: this keyboard stays
     * on screen until it is closed, and a label that stopped working after
     * an hour would be worse than no keyboard at all.
     *
     * Every other panel label is dropped first. Only one of these
     * keyboards can be on screen at a time, so only one may be bound at a
     * time; that is what makes "is this label bound?" a truthful answer to
     * "is this keyboard showing?".
     *
     * Built only for whoever is being handled right now, which is the
     * admin who typed /admin or pressed the button.
     */
    public function menuKeyboard(?string $section = null, Update|int|null $audience = null): array
    {
        $this->forgetLabels($audience);

        $items = $this->screens($section);

        $keyboard = $items === null || $section === null
            ? $this->sectionRows($audience)
            : $this->screenRows($section, $items, $audience);

        return $keyboard
            ->row(
                $this->label(self::HOME_LABEL, Run::core(OpenSection::name()), $audience),
                $this->label(self::CLOSE_LABEL, Run::core(CloseMenu::name()), $audience)
            )
            ->build();
    }

    /**
     * Puts the right keyboard on an admin's phone, if it is not there.
     *
     * A reply keyboard can only arrive attached to a message, and a
     * message can carry one markup -- so this cannot ride along with the
     * screen itself, which is carrying its inline buttons. Hence a line of
     * its own, and hence the check: navigating inside a section must not
     * post one of these on every press.
     */
    public function useMenu(Update $update, ?string $section = null, bool $force = false): void
    {
        if (!$force && $this->menuIsShowing($update, $section)) {
            return;
        }

        $items = $this->screens($section);
        $title = $items === null || $section === null ? null : $this->sections->find($section);

        $this->bot->sendMessage(
            $items === null || $title === null
                ? 'Admin menu is on the keyboard below.'
                : $title::title() . ' is on the keyboard below.'
        )
            ->to($update->chatId())
            ->replyMarkup($this->menuKeyboard($items === null ? null : $section, $update))
            ->execute();
    }

    /**
     * Whether this admin's keyboard is already the one asked for.
     *
     * Judged by the first label, which menuKeyboard() guarantees belongs
     * to one keyboard only: it drops every other panel label before
     * binding its own.
     */
    public function menuIsShowing(Update $update, ?string $section = null): bool
    {
        $telegramId = $this->telegramId($update);

        if ($telegramId === null) {
            return false;
        }

        $items = $this->screens($section);

        $label = $items === null
            ? (array_key_first($this->sectionLabels()) ?? self::HOME_LABEL)
            : (string) array_key_first($items);

        return $this->actions->findByLabel((int) $telegramId, $label) !== null;
    }

    /**
     * Which keyboard a section asks for, or null for the sections one.
     *
     * A section that contributes no screens is not a mistake -- it is
     * every core section today -- so it keeps the keyboard it arrived
     * with rather than being handed an empty one.
     *
     * @return array<string, string>|null
     */
    private function screens(?string $section): ?array
    {
        $items = $section === null ? [] : $this->subMenu($section);

        return $items === [] ? null : $items;
    }

    /**
     * A section's keyboard screens, empty when it contributes none.
     *
     * @return array<string, string> label => screen key
     */
    public function subMenu(string $key): array
    {
        $section = $this->sections->find($key);

        if ($section === null || !is_subclass_of($section, HasSubMenu::class)) {
            return [];
        }

        $items = [];

        foreach ($section::menuItems() as $label => $screen) {
            $label = trim((string) $label);
            $screen = trim((string) $screen);

            // Both halves have to survive the round trip: the label is the
            // whole payload on the way back, and the screen is what the
            // section is then asked for.
            if ($label !== '' && $screen !== '') {
                $items[$label] = $screen;
            }
        }

        return $items;
    }

    /**
     * Drops this admin's bound labels, for a keyboard being taken away.
     *
     * hideKeyboard() removes it from the screen; this removes what it
     * meant. Both are needed, or a binding would outlive its keyboard
     * and menuIsShowing() would answer for something nobody can see.
     */
    public function forgetMenu(Update|int $audience): void
    {
        $this->forgetLabels($audience);
    }

    /** Takes the reply keyboard away, without touching anything else. */
    public static function hideKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    /** Two sections per row, in registration order. */
    private function sectionRows(Update|int|null $audience): Keyboard
    {
        $keyboard = Keyboard::menu()->resize();
        $row = [];

        foreach ($this->sections->all() as $key => $class) {
            $row[] = $this->label(
                $class::title(),
                Run::core(OpenSection::name(), ['key' => $key]),
                $audience
            );

            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        return $keyboard;
    }

    /**
     * One section's own screens, two per row.
     *
     * @param array<string, string> $items
     */
    private function screenRows(string $section, array $items, Update|int|null $audience): Keyboard
    {
        $keyboard = Keyboard::menu()->resize();
        $row = [];

        foreach ($items as $label => $screen) {
            $row[] = $this->label(
                $label,
                Run::core(OpenSection::name(), ['key' => $section, 'screen' => $screen]),
                $audience
            );

            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        return $keyboard;
    }

    private function label(string $text, Run $run, Update|int|null $audience): MenuButton
    {
        $button = MenuButton::make($text)->action($run->never());

        return $audience === null ? $button : $button->audience($audience);
    }

    /**
     * Every label this panel may bind, dropped before a new keyboard.
     *
     * Without this, entering a section and then reopening the panel would
     * leave both keyboards' labels bound and menuIsShowing() would answer
     * for a keyboard that is no longer on screen.
     */
    private function forgetLabels(Update|int|null $audience): void
    {
        $telegramId = $this->telegramId($audience);

        if ($telegramId === null) {
            return;
        }

        foreach ([self::HOME_LABEL, self::CLOSE_LABEL] as $label) {
            $this->actions->forgetLabel((int) $telegramId, $label);
        }

        foreach ($this->sectionLabels() as $label => $_) {
            $this->actions->forgetLabel((int) $telegramId, (string) $label);
        }

        foreach ($this->sections->all() as $key => $_) {
            foreach ($this->subMenu($key) as $label => $__) {
                $this->actions->forgetLabel((int) $telegramId, (string) $label);
            }
        }
    }

    /** The admin a keyboard is being built for, as an integer id. */
    private function telegramId(Update|int|null $audience): ?int
    {
        if ($audience instanceof Update) {
            $id = $audience->fromId();

            return $id === null ? null : (int) $id;
        }

        return $audience;
    }

    /** @return array<string, string> section title => key */
    private function sectionLabels(): array
    {
        $labels = [];

        foreach ($this->sections->all() as $key => $class) {
            $labels[$class::title()] = $key;
        }

        return $labels;
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
