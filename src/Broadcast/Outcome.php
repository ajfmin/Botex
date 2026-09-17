<?php

namespace Botex\Broadcast;

use Botex\Telegram\Support\Request;

/**
 * What one delivery attempt came to.
 *
 * The whole point of a broadcast report is the difference between these.
 * "1,284 sent" on its own tells an admin nothing they can act on;
 * "1,102 delivered, 170 blocked you, 8 deleted their account, 4 failed"
 * tells them their real reach, and which number is worth investigating.
 *
 * So the classification is read off Telegram's own error codes rather
 * than guessed from a boolean:
 *
 *   ok                        -> SENT
 *   403                       -> BLOCKED    the person blocked the bot
 *   400 chat not found / …    -> GONE       the account is not there any more
 *   429                       -> THROTTLED  slow down and try this one again
 *   anything else, or nothing -> FAILED     worth a look
 *
 * BLOCKED and GONE are both permanent and both mean "do not spend rate
 * limit on this chat again", which is why isUnreachable() groups them --
 * but they are counted apart, because one is a person's decision about
 * your bot and the other is not.
 */
enum Outcome: string
{
    case SENT = 'sent';

    case BLOCKED = 'blocked';

    case GONE = 'gone';

    case THROTTLED = 'throttled';

    case FAILED = 'failed';

    /**
     * Descriptions that mean the chat will never accept a message again.
     *
     * Telegram answers all of these with 400, the same code it uses for
     * a malformed request, so the text is the only thing that separates
     * "this recipient is gone" from "this message was wrong" -- and the
     * two must not be counted together, or one bad parse_mode would look
     * like the whole audience deleting their accounts.
     */
    private const GONE_MARKERS = [
        'chat not found',
        'user is deactivated',
        'peer_id_invalid',
        'chat_id is empty',
        'bot was kicked',
        'group chat was upgraded',
    ];

    /** @param array<mixed> $response as the Bot API answered */
    public static function of(array $response): self
    {
        if (($response['ok'] ?? false) === true) {
            return self::SENT;
        }

        $code = (int) ($response['error_code'] ?? Request::NO_RESPONSE);
        $description = strtolower((string) ($response['description'] ?? ''));

        if ($code === 403) {
            return self::BLOCKED;
        }

        if ($code === 429) {
            return self::THROTTLED;
        }

        if ($code === 400) {
            foreach (self::GONE_MARKERS as $marker) {
                if (str_contains($description, $marker)) {
                    return self::GONE;
                }
            }
        }

        return self::FAILED;
    }

    /**
     * Seconds Telegram asked us to wait, when it asked.
     *
     * Capped, because the value arrives from the network and a broadcast
     * that obeys an absurd one would look like a hung worker. A real
     * flood-wait is seconds, not hours.
     */
    public static function retryAfter(array $response, int $max = 60): int
    {
        $seconds = (int) ($response['parameters']['retry_after'] ?? $response['retry_after'] ?? 1);

        return max(1, min($max, $seconds));
    }

    /** Whether this chat should be left out of future broadcasts. */
    public function isUnreachable(): bool
    {
        return $this === self::BLOCKED || $this === self::GONE;
    }

    /** The counter column this outcome adds to. */
    public function column(): string
    {
        return $this->value;
    }

    public function icon(): string
    {
        return match ($this) {
            self::SENT => '✅',
            self::BLOCKED => '🚫',
            self::GONE => '👻',
            self::THROTTLED => '🐢',
            self::FAILED => '⚠️',
        };
    }
}
