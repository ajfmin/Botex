<?php

namespace Botex\Bot;

use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * The placeholder a button wears while its work is being done.
 *
 * Telegram's own spinner is the wrong tool for anything that talks to a
 * VPN panel or moves money: it lives on the button, it is easy to miss,
 * and it says nothing about what is happening. Worse, it keeps the
 * keyboard pressable, so a customer who thinks nothing happened presses
 * "pay" again while the first press is still charging their wallet.
 *
 * So the screen itself says so. show() replaces the pressed message with
 * one line and *no* keyboard -- the removal is the point, not a side
 * effect -- and whatever the handler draws afterwards replaces that line
 * in turn. Every screen in this bot already edits the message it was
 * opened from, so the result needs no cooperation from the caller beyond
 * the one extra call: the success screen, the refusal notice and the
 * redraw after a failure all land on top of the placeholder by simply
 * doing what they already did.
 *
 * done() is for the handlers that have nothing to redraw -- the two
 * checkout buttons, which deliver into a fresh message -- and it is
 * their way of retiring the placeholder they put up.
 *
 * post() and finish() are the same idea for a conversation, which has
 * no message of ours on screen and no Update to reach one by: the last
 * thing in the chat is the admin's own typed answer, so the placeholder
 * is posted as a message of its own and the flow's reply is edited over
 * it. One message either way, which is what the press path gives too.
 *
 * All four are deliberately silent about failure. A placeholder that
 * could not be drawn is a cosmetic loss; the work behind it still has
 * to run, and its result still has to be reported.
 */
class Loading
{
    /** One line, edited over the screen the button was pressed on. */
    public const TEXT = 'loading ... ⏳';

    public function __construct(private Bot $bot)
    {
    }

    /**
     * Puts the placeholder where the pressed message was.
     *
     * @return bool false when there was no message of ours to replace,
     *              which is the case for a reply-keyboard press or a
     *              command -- the caller's own screen then arrives as a
     *              new message, as it always did
     */
    public function show(Update $update, string $text = self::TEXT): bool
    {
        $messageId = $update->messageId();
        $chatId = $update->chatId();

        if (!$update->isCallback() || $messageId === null || $chatId === null) {
            return false;
        }

        $this->bot->editMessage($text, $messageId)
            ->to($chatId)
            ->parseMode('HTML')
            ->execute();

        return true;
    }

    /**
     * Replaces the placeholder with how it turned out.
     *
     * Sends rather than edits when there was nothing to edit, so a
     * handler reached from a reply keyboard still reports its outcome.
     *
     * @param array<mixed> $keyboard
     */
    public function done(Update $update, string $text, array $keyboard = []): void
    {
        $messageId = $update->messageId();
        $chatId = $update->chatId();

        if ($chatId === null) {
            return;
        }

        $message = $update->isCallback() && $messageId !== null
            ? $this->bot->editMessage($text, $messageId)
            : $this->bot->sendMessage($text);

        $message->to($chatId)->parseMode('HTML');

        if ($keyboard !== []) {
            $message->replyMarkup($keyboard);
        }

        $message->execute();
    }

    /**
     * Posts the placeholder as a message of its own.
     *
     * For a caller with nothing on screen to replace -- a flow finishing
     * on a line the admin typed, a job reporting into a chat.
     *
     * @return int|null the id to hand back to finish(), or null when
     *                  Telegram did not accept it; finish() then simply
     *                  sends, so a caller need not check
     */
    public function post(int|string $chatId, string $text = self::TEXT): ?int
    {
        $response = $this->bot->sendMessage($text)
            ->to($chatId)
            ->parseMode('HTML')
            ->execute();

        $id = $response['result']['message_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Rewrites a posted placeholder with how it turned out.
     *
     * Sends instead when there is no placeholder to rewrite, so a caller
     * that skipped post() -- because it refused before doing any work --
     * still reports itself through the same call.
     *
     * @param array<mixed> $keyboard
     */
    public function finish(
        int|string $chatId,
        ?int $messageId,
        string $text,
        array $keyboard = []
    ): void {
        $message = $messageId === null
            ? $this->bot->sendMessage($text)
            : $this->bot->editMessage($text, $messageId);

        $message->to($chatId)->parseMode('HTML');

        if ($keyboard !== []) {
            $message->replyMarkup($keyboard);
        }

        $message->execute();
    }
}
