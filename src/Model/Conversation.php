<?php

namespace Botex\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted position of a user inside a multi-step flow.
 *
 * One row per user: starting a new flow replaces any unfinished one.
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    protected $fillable = [
        'telegram_id',
        'flow',
        'step',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
