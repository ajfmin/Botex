<?php

namespace Botex\Telegram\Builders\Keyboard;

use Botex\Bot\Action\Run;
use Botex\Telegram\Update;

class MenuButton
{

    private array $data = [];

    /** Set by action(), bound to this button's label when the keyboard builds. */
    private ?Run $run = null;

    private Update|int|null $audience = null;

    private function __construct(string $text)
    {
        $this->data['text'] = $text;
    }

    public static function make(string $text): self
    {

        $button = new self($text);
        return $button;

    }

    /**
     * Binds this button's label to a Run target.
     *
     * A reply-keyboard label carries nothing back on the wire, so the
     * binding is what makes it mean anything. It is scoped to one user:
     * the audience if one is named, otherwise whoever sent the update
     * being handled. Outside a request there is no ambient user, so
     * ->audience() becomes required.
     */
    public function action(Run $run): self
    {
        $this->run = $run;
        return $this;
    }

    /** Who the label is bound for, when it is not the current user. */
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
     * Marks the label as bound.
     *
     * Nothing goes into the button itself: the label is the whole payload,
     * so a menu binding leaves no trace on the wire.
     */
    public function resolved(): void
    {
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
