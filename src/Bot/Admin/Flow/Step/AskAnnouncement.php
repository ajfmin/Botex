<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Action\ActionStore;
use Botex\Bot\Conversation\Answer;
use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\StepInterface;
use Botex\Broadcast\BroadcastService;
use Botex\Telegram\Update;

/**
 * Collects the message itself -- whatever kind of message it is.
 *
 * Deliberately not a TextStep. An announcement is as often a picture of
 * a price list or a short video as it is a paragraph, and an admin who
 * has to describe their poster in words rather than send it is going to
 * paste it somewhere else instead. So this takes the message as it
 * arrives and remembers *where it is*, not what is in it: chat id plus
 * message id is enough for Telegram to repeat it to anyone, with its
 * formatting, its caption, its buttons and its file, and none of it has
 * to be re-uploaded per recipient.
 *
 * Which is also why nothing here validates the content. It is the
 * admin's own message, already rendered on their own screen exactly as
 * every recipient will see it; there is nothing a validator could add
 * except a way to reject something Telegram was happy with.
 */
class AskAnnouncement implements StepInterface
{
    public function __construct(
        private BroadcastService $broadcasts,
        private ActionStore $actions
    ) {
    }

    public static function name(): string
    {
        return 'announcement';
    }

    public function prompt(Session $session): Prompt
    {
        $audience = $this->broadcasts->audience();

        return new Prompt(implode(PHP_EOL, [
            '<b>New broadcast</b>',
            '',
            'Send the message you want everyone to get. Text, a photo, a '
                . 'video, a file -- whatever you send is copied to each '
                . 'person exactly as it looks here.',
            '',
            'It reaches <b>' . number_format($audience) . '</b> '
                . ($audience === 1 ? 'person' : 'people')
                . ', over about ' . $this->broadcasts->duration($this->broadcasts->estimate()) . '.',
        ]));
    }

    public function validate(Update $update, Session $session): Answer
    {
        $messageId = $update->isMessage() ? $update->messageId() : null;
        $chatId = $update->chatId();

        if ($messageId === null || $chatId === null) {
            return Answer::error('Send the message itself, as a message.');
        }

        // A reply-keyboard tap arrives as an ordinary text message, and a
        // flow gets first refusal on those -- which is right everywhere
        // else and wrong here, because the mistake it lets through is
        // broadcasting the words "🏠 Admin" to everybody. So a label this
        // admin has a button bound to is refused rather than taken as the
        // announcement. Text that happens to look like one is fine: what
        // is checked is whether a button of theirs is actually bound to
        // it right now.
        $text = trim((string) $update->text());

        if ($text !== '' && $this->actions->findByLabel($session->telegramId, $text) !== null) {
            return Answer::error(
                'That is a menu button, not a message. Send what you want '
                    . 'people to receive, or press Cancel.'
            );
        }

        // Both halves travel, because a copy is made from a chat the bot
        // can see rather than from a message id floating on its own.
        return Answer::ok(['chat' => (int) $chatId, 'message' => (int) $messageId]);
    }
}
