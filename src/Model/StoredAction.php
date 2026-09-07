<?php

namespace Botex\Model;

use Botex\Bot\Action\Run;
use Illuminate\Database\Eloquent\Model;

/**
 * A rendered button's intent, kept so a later press can be resolved.
 *
 * Telegram returns only callback data or a reply-keyboard label, so the
 * extension, action and structured data live here instead. Rows are
 * disposable: expiry and pruning are expected, and a missing row simply
 * means the button is stale.
 */
class StoredAction extends Model
{
    protected $table = 'run_actions';

    protected $fillable = [
        'token',
        'kind',
        'telegram_id',
        'target',
        'extension',
        'action',
        'label',
        'data',
        'presses',
        'once',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'presses' => 'integer',
        'once' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Null expires_at means the action was minted to outlive the day. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** A single-use action is spent once it has been run. */
    public function isSpent(): bool
    {
        return (bool) $this->once && $this->used_at !== null;
    }

    public function isLive(): bool
    {
        return !$this->isExpired() && !$this->isSpent();
    }

    public function isInline(): bool
    {
        return $this->kind === 'inline';
    }

    /**
     * Whether this row runs a command rather than a runnable.
     *
     * Rows written before the column existed default to an action, which
     * is what every row was then.
     */
    public function isCommand(): bool
    {
        return $this->target === Run::COMMAND;
    }

    /** How this row is addressed in the Runnables allowlist. */
    public function key(): string
    {
        return $this->extension . ':' . $this->action;
    }

    /**
     * The text a command row presses as, e.g. "/remind 30".
     *
     * Rebuilt from the stored verb and arguments, so what reaches the
     * router is assembled from parts that were validated when the button
     * was built, never a free-form string off the wire.
     */
    public function commandText(): string
    {
        $data = is_array($this->data) ? $this->data : [];
        $arguments = $data['args'] ?? [];
        $text = '/' . $this->action;

        return is_array($arguments) && $arguments !== []
            ? $text . ' ' . implode(' ', array_map('strval', $arguments))
            : $text;
    }

    /**
     * Deliberately no press() here.
     *
     * Recording a press is a claim, not a save: two simultaneous presses
     * of a single-use action would both read an unused row and both run.
     * ActionStore::claim() does it in one conditional UPDATE instead.
     */
}
