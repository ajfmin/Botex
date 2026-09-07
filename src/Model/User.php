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
        'status'
    ];

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }
}
