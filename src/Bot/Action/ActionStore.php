<?php

namespace Botex\Bot\Action;

use Botex\Model\StoredAction;
use Botex\Support\Schema;
use Illuminate\Support\Carbon;

/**
 * Persistence for rendered actions.
 *
 * Table-backed for the same reason conversations are: a user can press
 * two buttons at once, and a file would interleave. Rows are cheap and
 * disposable, so lookups always tolerate a miss.
 */
class ActionStore
{
    /**
     * How long a spent single-use row is kept before pruning.
     *
     * Keeping it briefly is what makes a double tap report "already
     * done" instead of the vaguer "no longer available".
     */
    public const SPENT_GRACE = 86400;

    /**
     * Writes an action and returns its row.
     *
     * @param array<string, mixed> $data
     */
    public function put(
        ActionToken $token,
        Run $run,
        ?int $telegramId,
        ?string $label,
        ?int $ttl
    ): StoredAction {
        return StoredAction::create([
            'token' => $token->value,
            'kind' => $token->kind,
            'telegram_id' => $telegramId,
            // Which allowlist resolves this row: a command verb, or an
            // extension's runnable. Stored so the press path never has to
            // guess from the shape of the data.
            'target' => $run->target,
            'extension' => $run->extension,
            'action' => $run->name,
            'label' => $label,
            'data' => $run->data,
            'presses' => 0,
            'once' => $run->once,
            'expires_at' => $ttl === null || $ttl === Run::NO_EXPIRY
                ? null
                : Carbon::now()->addSeconds($ttl),
        ]);
    }

    /**
     * Atomically claims a press, and reports whether this caller won it.
     *
     * The single-use guard is in the WHERE clause, not in PHP, because a
     * read-then-write lets simultaneous presses both see an unused row
     * and both run. Only one UPDATE can match, so only one caller gets an
     * affected row back. A reusable action always matches, which is what
     * reusable means.
     *
     * @return bool true if this press should run the action
     */
    public function claim(int $id): bool
    {
        $claimed = StoredAction::where('id', $id)
            ->where(function ($query) {
                $query->where('once', false)->orWhereNull('used_at');
            })
            ->increment('presses', 1, ['used_at' => Carbon::now()]);

        return $claimed === 1;
    }

    /** Looks a token up regardless of state, so callers can explain why. */
    public function find(ActionToken $token): ?StoredAction
    {
        return StoredAction::where('token', $token->value)
            ->where('kind', $token->kind)
            ->first();
    }

    /**
     * Resolves a reply-keyboard label for one user.
     *
     * Scoped by telegram_id and taken newest-first, which is what keeps
     * two users, or two extensions, from fighting over the same label.
     * Live rows win outright; a spent or expired one is returned only
     * when nothing live matches, so the notice can be specific.
     */
    public function findByLabel(int $telegramId, string $label): ?StoredAction
    {
        $matches = StoredAction::where('kind', ActionToken::REPLY)
            ->where('telegram_id', $telegramId)
            ->where('label', $label)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($matches as $row) {
            if ($row->isLive()) {
                return $row;
            }
        }

        return $matches->first();
    }

    /**
     * Drops earlier rows for the same user and label.
     *
     * A reply keyboard replaces the previous one on screen, so its old
     * bindings are unreachable and would only pile up.
     */
    public function forgetLabel(int $telegramId, string $label): void
    {
        StoredAction::where('kind', ActionToken::REPLY)
            ->where('telegram_id', $telegramId)
            ->where('label', $label)
            ->delete();
    }

    /** Removes every action belonging to an extension. */
    public function forgetExtension(string $slug): int
    {
        return StoredAction::where('extension', $slug)->delete();
    }

    /**
     * Deletes expired rows and long-spent single-use rows.
     *
     * @return int rows removed
     */
    public function prune(): int
    {
        $now = Carbon::now();

        $expired = StoredAction::whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->delete();

        $spent = StoredAction::where('once', true)
            ->whereNotNull('used_at')
            ->where('used_at', '<', $now->copy()->subSeconds(self::SPENT_GRACE))
            ->delete();

        return (int) $expired + (int) $spent;
    }

    public function count(): int
    {
        return StoredAction::count();
    }

    public function countLive(): int
    {
        return StoredAction::where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>=', Carbon::now());
        })->where(function ($query) {
            $query->where('once', false)->orWhereNull('used_at');
        })->count();
    }

    /** @return array<int, StoredAction> */
    public function recent(int $limit = 20): array
    {
        return StoredAction::orderByDesc('id')->limit($limit)->get()->all();
    }

    /** Creates the run_actions table. Safe to call repeatedly. */
    public static function migrate(): void
    {
        Schema::createIfMissing('run_actions', function ($table) {
            $table->id();
            $table->string('token', 64);
            $table->string('kind', 16)->default(ActionToken::INLINE);
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->string('target', 16)->default(Run::ACTION);
            $table->string('extension');
            $table->string('action');
            $table->string('label')->nullable();
            $table->text('data')->nullable();
            $table->unsignedInteger('presses')->default(0);
            $table->boolean('once')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['token', 'kind']);
            $table->index(['kind', 'telegram_id', 'label']);
            $table->index('extension');
            $table->index('expires_at');
        });
    }
}
