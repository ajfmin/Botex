<?php

namespace Botex\Bot\Conversation;

use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;

/**
 * What a step shows the user when it asks its question.
 */
class Prompt
{
    /** @param array<int, array<InlineButton>> $rows inline button rows */
    public function __construct(
        public readonly string $text,
        private array $rows = []
    ) {
    }

    /**
     * Builds the reply markup, always appending a Cancel button so a
     * user is never stranded inside a flow.
     */
    public function keyboard(): array
    {
        $keyboard = Keyboard::inline();

        foreach ($this->rows as $row) {
            $keyboard->row(...$row);
        }

        return $keyboard
            ->row(InlineButton::callback('Cancel', FlowRunner::CANCEL))
            ->build();
    }
}
