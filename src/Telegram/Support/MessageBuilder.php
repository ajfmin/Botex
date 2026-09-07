<?php

namespace Botex\Telegram\Support;

class MessageBuilder
{

    private array $data = [];
    private \Botex\Telegram\Support\Request $bot;

    private string $method;

    private ?array $response = null;

    public function __construct(Request $bot, string $method, array $input)
    {
        $this->bot = $bot;
        $this->data = $input;
        $this->method = $method;

    }

    public function to(int|string $chatId): self
    {
        $this->data['chat_id'] = $chatId;
        return $this;
    }

    public function parseMode(string $mode): self
    {
        $this->data['parse_mode'] = $mode;
        return $this;
    }
    public function replyMarkup(array $markup): self
    {
        $this->data['reply_markup'] = json_encode($markup);
        return $this;
    }

    public function replyTo(int $messageId): self
    {
        $this->data['reply_to_message_id'] = $messageId;
        return $this;
    }

    /**
     * Sends the request once. Calling this again returns the cached
     * response instead of hitting the API a second time, so an
     * explicit execute() and the __destruct fallback can coexist.
     */
    public function execute(): array
    {
        if ($this->response !== null) {
            return $this->response;
        }

        return $this->response = $this->bot->execute($this->method, $this->data);
    }

    public function __destruct()
    {
        $this->execute();
    }

}