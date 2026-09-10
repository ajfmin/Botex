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
     *
     * With $alert the text arrives as a popup the person has to dismiss
     * rather than as a toast that fades on its own. That is the right
     * shape for a refusal -- "this could not be done, and here is why" is
     * worth interrupting for -- and the wrong one for anything routine.
     *
     * Telegram caps the text at 200 characters and rejects a longer one,
     * so it is cut here rather than losing the whole answer.
     */
    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        return $this->request->execute('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($text, 0, 200),
            'show_alert' => $alert,
        ]);
    }

}