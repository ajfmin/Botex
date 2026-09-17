<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\ChoiceStep;
use Botex\Broadcast\BroadcastService;

/**
 * The last look before a message reaches everybody.
 *
 * Worth a step of its own for the reason a wallet adjustment is: this is
 * the one action in the panel that cannot be taken back. A credit can be
 * refunded and a blocked user unblocked, but a message that has arrived
 * on ten thousand phones has arrived.
 *
 * There is no preview rendered here on purpose -- the admin's own
 * message is the one directly above this question, which is a better
 * preview than any copy of it could be.
 *
 * Two choices, because silent is a real decision at this size: an
 * announcement that buzzes every phone at once is how a bot gets muted.
 */
class ConfirmAnnouncement extends ChoiceStep
{
    public const SEND = 'send';

    public const QUIET = 'quiet';

    protected int $perRow = 1;

    public function __construct(
        private BroadcastService $broadcasts
    ) {
    }

    /**
     * Not plain "confirm".
     *
     * A choice step namespaces its callback data with its own name,
     * and the wallet flow already has a step called that. Two steps
     * sharing a namespace is safe -- each validates against its own
     * choices -- but a stale button then fails with "that option is no
     * longer available" rather than being ignored, which reads as a
     * bug in whichever flow the admin is standing in.
     */
    public static function name(): string
    {
        return 'confirm_broadcast';
    }

    public function question(Session $session): string
    {
        $audience = $this->broadcasts->audience();

        return implode(PHP_EOL, [
            'Send <b>the message above</b> to <b>' . number_format($audience) . '</b> '
                . ($audience === 1 ? 'person' : 'people') . '?',
            '',
            'It goes out at ' . $this->broadcasts->rate() . ' a second, so it takes about '
                . $this->broadcasts->duration($this->broadcasts->estimate()) . '.',
            'You can pause or stop it from the Broadcast screen while it runs.',
        ]);
    }

    /** @return array<string, string> */
    public function choices(Session $session): array
    {
        return [
            self::SEND => '📣 Send it',
            self::QUIET => '🔕 Send it quietly',
        ];
    }
}
