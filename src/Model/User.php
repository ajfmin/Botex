<?php

namespace Botex\Model;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    protected $fillable = [
        'telegram_id',
        'phone_number',
        'status',
        'unreachable_at',
    ];

    protected $casts = [
        'unreachable_at' => 'datetime',
    ];

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    /**
     * Whether Telegram has refused to deliver to this chat.
     *
     * Set by a broadcast, never by an admin, and separate from
     * isBlocked() for that reason: one is the bot's decision about the
     * person, the other is the person's decision about the bot.
     */
    public function isUnreachable(): bool
    {
        return $this->unreachable_at !== null;
    }
}
