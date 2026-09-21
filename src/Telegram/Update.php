<?php

namespace Botex\Telegram;

class Update
{
    public function __construct(
        private array $data
    ) {
    }

    public function raw(): array
    {
        return $this->data;
    }

    public function isMessage(): bool
    {
        return isset($this->data['message']);
    }

    public function isCallback(): bool
    {
        return isset($this->data['callback_query']);
    }

    public function text(): ?string
    {
        return $this->data['message']['text'] ?? null;
    }

    public function messageId(): ?int
    {
        $id = $this->isCallback()
            ? $this->data['callback_query']['message']['message_id'] ?? null
            : $this->data['message']['message_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** Id of the callback query itself, needed to answer it. */
    public function callbackId(): ?string
    {
        return $this->data['callback_query']['id'] ?? null;
    }

    /**
     * Whether the message this press came from can be edited as text.
     *
     * editMessageText only works on a message that *has* text. A button
     * sitting under a photo caption is on a message that has none, and
     * Telegram answers "there is no text in the message to edit" --
     * which the bot throws away, so the press looks like a dead button.
     *
     * Every screen in this bot edits in place when it can, so each of
     * them has to ask this first now that a service can be handed over
     * as the caption of its own QR. The honest answer for such a press
     * is a new message, which is what the screens already do for a
     * command or a keyboard tap.
     */
    public function canEditText(): bool
    {
        if (!$this->isCallback()) {
            return false;
        }

        $message = $this->data['callback_query']['message'] ?? null;

        return is_array($message) && ($message['text'] ?? null) !== null;
    }

    public function fromId(): ?string
    {
        if ($this->isCallback()) {
            return $this->data['callback_query']['from']['id'] ?? null;
        }

        return $this->data['message']['from']['id'] ?? null;
    }

    public function chatId(): int|string|null
    {
        if ($this->isMessage()) {
            return $this->data['message']['chat']['id'] ?? null;
        }

        if ($this->isCallback()) {
            return $this->data['callback_query']['message']['chat']['id'] ?? null;
        }

        return null;
    }

    public function callbackData(): ?string
    {
        return $this->data['callback_query']['data'] ?? null;
    }

    /**
     * The same update, seen as if the user had typed $text.
     *
     * A Run button bound to a command presses as callback data, but the
     * command it reaches reads its arguments from message text. Rather than
     * teach every command about two input shapes, the update is presented
     * in the one shape they already handle.
     *
     * The callback parts are kept alongside, so a command that wants to
     * know it was pressed can still see the press. chat and from are copied
     * across, so nothing infers a different user from the rewrite.
     */
    public function asCommand(string $text): self
    {
        $data = $this->data;

        $message = $this->isCallback()
            ? ($data['callback_query']['message'] ?? [])
            : ($data['message'] ?? []);

        $message['text'] = $text;

        // A callback's message carries the bot as its sender, since the bot
        // posted it. The command has to see the user who pressed.
        if ($this->isCallback() && isset($data['callback_query']['from'])) {
            $message['from'] = $data['callback_query']['from'];
        }

        $data['message'] = $message;

        return new self($data);
    }
}