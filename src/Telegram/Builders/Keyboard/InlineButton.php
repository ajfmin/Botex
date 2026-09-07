<?php

namespace Botex\Telegram\Builders\Keyboard;

use Botex\Bot\Action\Run;
use Botex\Telegram\Update;

class InlineButton
{

    private array $data = [];

    /** Set by action(), resolved to callback data when the keyboard builds. */
    private ?Run $run = null;

    private Update|int|null $audience = null;

    private function __construct(string $text)
    {
        $this->data['text'] = $text;
    }

    /**
     * A button with no target yet.
     *
     * For ->action() to fill in, so a Run button does not have to invent a
     * callback string it will never use.
     */
    public static function make(string $text): self
    {
        return new self($text);
    }

    /**
     * @param string|null $callback null when ->action() will supply the
     *                              target instead
     */
    public static function callback(string $text, ?string $callback = null): self
    {
        $button = new self($text);

        if ($callback !== null) {
            $button->data['callback_data'] = $callback;
        }

        return $button;
    }

    public static function url(string $text, string $callback): self
    {
        $button = new self($text);
        $button->data['url'] = $callback;
        return $button;
    }

    /**
     * Binds this button to a Run target.
     *
     * Nothing is persisted here. The keyboard binds on build(), so a button
     * that is described but never rendered writes no row.
     */
    public function action(Run $run): self
    {
        $this->run = $run;
        return $this;
    }

    /**
     * Who the button is being shown to.
     *
     * Optional for an inline button, whose token identifies its row on its
     * own. Kept for parity with MenuButton, and so an inline action bound
     * outside a request can still record its audience.
     */
    public function audience(Update|int $audience): self
    {
        $this->audience = $audience;
        return $this;
    }

    public function emoji(string $emojiId): self
    {

        $this->data['icon_custom_emoji_id'] = $emojiId;
        return $this;

    }


    public function style(string $style): self
    {

        $this->data['style'] = $style;
        return $this;

    }

    /** A Run target still waiting to be bound. */
    public function pending(): ?Run
    {
        return $this->run;
    }

    /**
     * Accepts the callback data a binding produced.
     *
     * Called by Keyboard, which owns the binder; clearing the run means a
     * keyboard built twice does not mint a second token.
     */
    public function resolved(string $callbackData): void
    {
        $this->data['callback_data'] = $callbackData;
        $this->run = null;
    }

    public function audienceFor(): Update|int|null
    {
        return $this->audience;
    }

    public function text(): string
    {
        return (string) $this->data['text'];
    }

    public function toArray(): array
    {
        return $this->data;
    }

}
