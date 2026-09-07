<?php

namespace Botex\Telegram\Builders\Keyboard;

use Botex\Bot\Action\ActionBinder;
use Botex\Bot\Feeder;

class Keyboard
{

    private const INLINE = 'inline_keyboard';
    private const MENU = 'keyboard';

    /** @var array<int, array<int, InlineButton|MenuButton>> */
    private array $rows = [];
    private array $options = [];

    /** Resolved only when a button actually needs it. */
    private ?ActionBinder $binder = null;

    public function __construct(
        private string $type
    ) {

    }

    public static function inline(): self
    {
        return new self(self::INLINE);
    }

    public static function menu(): self
    {
        return new self(self::MENU);
    }

    /**
     * Buttons are kept as objects until build().
     *
     * A Run target is bound at build time, so describing a keyboard and
     * never sending it writes nothing to the store.
     */
    public function row(InlineButton|MenuButton ...$buttons): self
    {
        foreach ($buttons as $button) {
            $expected = $this->type === self::INLINE ? InlineButton::class : MenuButton::class;

            // Telegram would reject the mixed markup anyway, with a message
            // that says nothing about which button was wrong.
            if (!$button instanceof $expected) {
                throw new \InvalidArgumentException(
                    'A ' . ($this->type === self::INLINE ? 'inline' : 'menu')
                    . ' keyboard takes ' . ($this->type === self::INLINE ? 'InlineButton' : 'MenuButton')
                    . 's; got ' . $button::class . " for '{$button->text()}'."
                );
            }
        }

        $this->rows[] = $buttons;
        return $this;

    }

    public function resize(bool $resize = true): self
    {
        if ($this->type !== self::MENU) {
            throw new \Exception('Resize option is only available for menu keyboards.');
        }
        $this->options['resize_keyboard'] = $resize;
        return $this;

    }

    /**
     * Lets a caller supply the binder rather than have it resolved.
     *
     * Only needed where the container is not reachable, e.g. a test.
     */
    public function bindWith(ActionBinder $binder): self
    {
        $this->binder = $binder;
        return $this;
    }

    public function build(): array
    {

        $rows = [];

        foreach ($this->rows as $buttons) {
            $row = [];

            foreach ($buttons as $button) {
                $this->bindAction($button);

                $row[] = $button->toArray();
            }

            $rows[] = $row;
        }

        return [$this->type => $rows] + $this->options;

    }

    /**
     * Persists a button's Run target and gives it back what it needs.
     *
     * An inline button gets callback data; a menu button gets nothing on
     * the wire, because its label is the whole payload.
     */
    private function bindAction(InlineButton|MenuButton $button): void
    {
        $run = $button->pending();

        if ($run === null) {
            return;
        }

        $binder = $this->binder();

        if ($button instanceof InlineButton) {
            $button->resolved($binder->inline($run, $button->audienceFor()));

            return;
        }

        $binder->menu($run, $button->text(), $button->audienceFor());
        $button->resolved();
    }

    /**
     * The container's binder.
     *
     * Keyboards are built with static constructors all over the codebase,
     * so there is nowhere to inject this. Resolved lazily, and only for a
     * keyboard that actually carries an action, which keeps a plain
     * callback keyboard free of any container involvement.
     */
    private function binder(): ActionBinder
    {
        if ($this->binder !== null) {
            return $this->binder;
        }

        $feeder = Feeder::instance();

        if ($feeder === null) {
            throw new \RuntimeException(
                'A Run action cannot be bound without the container. '
                . 'Boot through bootstrap/app.php, or pass one with '
                . 'Keyboard::bindWith($binder).'
            );
        }

        /** @var ActionBinder $binder */
        $binder = $feeder->make(ActionBinder::class);

        return $this->binder = $binder;
    }

}
