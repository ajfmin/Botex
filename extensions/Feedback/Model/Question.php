<?php

namespace Extensions\Feedback\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A question an admin has added. Extension models are ordinary Eloquent
 * models; only the table name is namespaced to avoid collisions.
 */
class Question extends Model
{
    protected $table = 'feedback_questions';

    protected $fillable = [
        'text',
        'position',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'position' => 'integer',
    ];
}
