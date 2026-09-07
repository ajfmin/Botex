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