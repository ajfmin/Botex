<?php

namespace Botex\Bot\Conversation;

use Botex\Model\Conversation;

/**
 * Persistence for conversation sessions.
 *
 * Backed by a table rather than a file so concurrent updates from the
 * same user cannot interleave into a half-written state.
 */
class Store
{
    public function find(int $telegramId): ?Session
    {
        $row = Conversation::where('telegram_id', $telegramId)->first();

        if (!$row) {
            return null;
        }

        return new Session(
            telegramId: $telegramId,
            flow: (string) $row->flow,
            step: (string) $row->step,
            data: is_array($row->data) ? $row->data : []
        );
    }

    public function save(Session $session): void
    {
        Conversation::updateOrCreate(
            ['telegram_id' => $session->telegramId],
            [
                'flow' => $session->flow,
                'step' => $session->step,
                'data' => $session->all(),
            ]
        );
    }

    public function clear(int $telegramId): void
    {
        Conversation::where('telegram_id', $telegramId)->delete();
    }

    /** Creates the conversations table. Safe to call repeatedly. */
    public static function migrate(): void
    {
        \Botex\Support\Schema::createIfMissing('conversations', function ($table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('flow');
            $table->string('step');
            $table->text('data')->nullable();
            $table->timestamps();
        });
    }
}
