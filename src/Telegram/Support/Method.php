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
     * Repeats a message the bot can see, as a new message of its own.
     *
     * Not a forward: a copy carries no "forwarded from" header and no
     * link back to where it came from, which is what makes it the right
     * shape for an announcement. The admin composes it once, in their own
     * chat with the bot, and every recipient sees it as if it had been
     * written to them.
     *
     * It is also the only way to broadcast something that is not text
     * without the sender re-uploading it per recipient: Telegram already
     * holds the photo, so a copy is one small API call whatever the
     * message weighs. See Botex\Broadcast\Sender.
     */
    public function copyMessage(int|string $fromChatId, int $messageId): MessageBuilder
    {
        return new MessageBuilder(
            $this->request,
            'copyMessage',
            [
                'from_chat_id' => $fromChatId,
                'message_id' => $messageId,
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
