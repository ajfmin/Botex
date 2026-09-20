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
     * Puts text on the person's clipboard.
     *
     * There is no round trip: Telegram copies it on the device and shows
     * its own confirmation, so the bot is never told the button was
     * pressed and there is nothing to answer. That is what makes it the
     * right shape for anything the customer has to paste somewhere else
     * -- a subscription link, a card number, a service code -- where a
     * callback that echoed the value back would only add a message to
     * scroll past.
     *
     * The label and the copied text are separate on purpose, so the
     * button can read "copy link" while carrying the link itself. Pass
     * the same string twice when the button *is* the value.
     *
     * @param string $payload what lands on the clipboard; Telegram caps
     *                        this at 256 characters and rejects the
     *                        whole message if it is longer
     */
    public static function copy(string $text, string $payload): self
    {
        $button = new self($text);
        $button->data['copy_text'] = ['text' => $payload];
        return $button;
    }

    /**
     * Hands something on to another chat.
     *
     * Pressing it opens Telegram's chat picker; the chosen chat gets the
     * bot's username and $query dropped into its input box, ready to
     * send. What comes back is an inline query, not a press of this
     * button -- so the bot needs inline mode switched on with BotFather
     * for anything to come of it. Without that the person is left
     * holding a username in a text box.
     *
     * The default empty query is the plain "pass this bot on" button.
     */
    public static function share(string $text, string $query = ''): self
    {
        $button = new self($text);
        $button->data['switch_inline_query'] = $query;
        return $button;
    }

    /**
     * Opens a Web App inside Telegram.
     *
     * The URL has to be https, and Telegram only accepts this on an
     * inline keyboard in a private chat with the bot -- which is every
     * chat this bot has, but worth knowing before putting one in a
     * channel post.
     *
     * Whatever the app sends back arrives as its own `web_app_data`
     * update rather than as a press of this button, so there is nothing
     * to answer here either.
     */
    public static function webApp(string $text, string $url): self
    {
        $button = new self($text);
        $button->data['web_app'] = ['url' => $url];
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
