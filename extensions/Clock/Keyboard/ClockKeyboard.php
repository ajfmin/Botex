<?php

namespace Extensions\Clock\Keyboard;

use Botex\Bot\Action\Run;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Builders\Keyboard\MenuButton;
use Botex\Telegram\Update;
use Extensions\Clock\Action\ShowTime;
use Extensions\Clock\Service\ClockService;

/**
 * An instance rather than a static builder, because the labels depend on
 * settings. Resolved through Feeder like any other class.
 *
 * Note what it no longer takes: nothing to do with actions. A button
 * declares its own target with ->action(), and the keyboard binds it on
 * build().
 */
class ClockKeyboard
{
    public function __construct(
        private ClockService $clock
    ) {
    }

    /**
     * One Run button per configured zone, each carrying its zone as
     * structured data.
     *
     * The plain clock:refresh button stays alongside them, so both the
     * fixed-callback and the Run styles remain in use.
     */
    public function inline(Update $update, ?string $current = null): array
    {
        $keyboard = Keyboard::inline();
        $current ??= $this->clock->defaultZone();
        $row = [];

        foreach ($this->clock->zones() as $zone) {
            $label = $zone === $current ? '• ' . $this->short($zone) : $this->short($zone);

            // The slug is inferred from this namespace, so 'Clock' is not
            // repeated at every call site.
            $row[] = InlineButton::make($label)
                ->action(Run::extension(ShowTime::name(), ['zone' => $zone]));

            // Two per row keeps the labels readable on a phone.
            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        return $keyboard
            ->row(InlineButton::callback('Refresh', 'clock:refresh'))
            ->build();
    }

    /**
     * A reply keyboard whose labels are bound per user.
     *
     * Nothing distinguishes these labels on the wire, so the binding is
     * what makes them mean anything; two users pressing "UTC" resolve to
     * their own rows. The audience comes from the update being handled.
     */
    public function menu(Update $update): array
    {
        $keyboard = Keyboard::menu();
        $row = [];

        foreach ($this->clock->zones() as $zone) {
            $row[] = MenuButton::make($this->short($zone))
                ->action(Run::extension(ShowTime::name(), ['zone' => $zone]));

            if (count($row) === 2) {
                $keyboard->row(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard->row(...$row);
        }

        // A command reached from a button, to show that a Run target can be
        // a command as well as a runnable: this presses as "/remind 30".
        return $keyboard
            ->row(MenuButton::make('Remind me in 30')
                ->action(Run::command('remind', [30])))
            ->resize()
            ->build();
    }

    /** "Europe/London" reads as "London" on a button. */
    private function short(string $zone): string
    {
        $parts = explode('/', $zone);

        return str_replace('_', ' ', (string) end($parts));
    }
}
