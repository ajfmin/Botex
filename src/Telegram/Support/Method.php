<?php

namespace Botex\Telegram\Support;

trait Method
{

    public function sendMessage(string $text): MessageBuilder
    {
        return new MessageBuilder(
            $this->request,
            'sendMessage',
            [
                'text' => $text
            ]
        );
    }

    public function editMessage(string $text, int $messageId): MessageBuilder
    {
        return new MessageBuilder(
            $this->request,
            'editMessageText',
            [
                'text' => $text,
                'message_id' => $messageId
            ]
        );
    }

    /**
     * Clears the loading spinner on an inline button. Telegram shows
     * the button as stuck until this is sent.
     */
    public function answerCallback(string $callbackId, string $text = ''): array
    {
        return $this->request->execute('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]);
    }

}