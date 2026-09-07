<?php

namespace Extensions\Feedback\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * One submitted feedback form: the rating plus a question => answer map.
 */
class Response extends Model
{
    protected $table = 'feedback_responses';

    protected $fillable = [
        'telegram_id',
        'rating',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
        'rating' => 'integer',
    ];
}
