<?php

namespace Botex\Bot\Command;

use Botex\Bot\Conversation\Store;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Text escape hatch out of a flow, for when the inline Cancel button has
 * scrolled out of reach.
 */
class Cancel implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private Store $store
    ) {
    }

    public static function command(): string
    {
        return '/cancel';
    }

    public static function button(): string
    {
        return 'Cancel';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $fromId = $update->fromId();

        if ($fromId === null) {
            return;
        }

        $active = $this->store->find((int) $fromId) !== null;
        $this->store->clear((int) $fromId);

        $this->bot->sendMessage($active ? 'Cancelled.' : 'Nothing to cancel.')
            ->to($update->chatId());
    }
}
