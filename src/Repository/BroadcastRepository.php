<?php

namespace Botex\Repository;

use Botex\Broadcast\Status;
use Botex\Model\Broadcast;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;

/**
 * Reads and writes broadcast rows.
 *
 * Every counter update here is a conditional or atomic UPDATE rather
 * than a read-modify-write. The worker is incrementing these while the
 * admin panel is reading them and possibly pausing the same row, and a
 * save() of a model loaded seconds ago would quietly put back the status
 * it was loaded with -- un-pausing a broadcast an admin just stopped.
 */
class BroadcastRepository
{
    public function find(int $id): ?Broadcast
    {
        return Broadcast::find($id);
    }

    public function create(array $attributes): Broadcast
    {
        return Broadcast::create($attributes);
    }

    /** @return array<Broadcast> newest first */
    public function recent(int $limit = 10): array
    {
        return Broadcast::orderByDesc('id')->limit($limit)->get()->all();
    }

    /** The one being sent right now, if any. */
    public function active(): ?Broadcast
    {
        return Broadcast::whereIn('status', [Status::QUEUED->value, Status::SENDING->value])
            ->orderBy('id')
            ->first();
    }

    /** @return array<Broadcast> queued, sending or paused */
    public function unfinished(): array
    {
        return Broadcast::whereIn('status', [
            Status::QUEUED->value,
            Status::SENDING->value,
            Status::PAUSED->value,
        ])->orderBy('id')->get()->all();
    }

    /**
     * Moves a broadcast to a new state, only from the ones that allow it.
     *
     * The allowed-from list is what makes this safe to call from the
     * panel: a Resume pressed twice, or pressed on a broadcast that
     * finished while the message sat on screen, changes nothing and
     * returns false rather than reviving it.
     *
     * @param array<Status> $from
     */
    public function transition(int $id, Status $to, array $from, array $extra = []): bool
    {
        $allowed = array_map(static fn (Status $status): string => $status->value, $from);

        return Broadcast::where('id', $id)
            ->whereIn('status', $allowed)
            ->update(array_merge($extra, [
                'status' => $to->value,
                'updated_at' => Carbon::now(),
            ])) > 0;
    }

    /**
     * Adds a slice's tallies to the row and moves the cursor.
     *
     * One statement, so a panel reading mid-flush sees a consistent set
     * rather than the sent count of this slice against the cursor of the
     * last one.
     *
     * @param array<string,int> $tally outcome value => how many
     */
    public function tally(int $id, array $tally, int $cursor, ?string $lastError = null): void
    {
        $updates = ['cursor' => $cursor, 'updated_at' => Carbon::now()];

        if ($lastError !== null) {
            $updates['last_error'] = mb_substr($lastError, 0, 500);
        }

        foreach ($tally as $column => $count) {
            if ($count > 0 && $this->isCounter($column)) {
                $updates[$column] = Capsule::connection()->raw("`{$column}` + " . (int) $count);
            }
        }

        Broadcast::where('id', $id)->update($updates);
    }

    /** @return array<string> the columns tally() may touch */
    public function counters(): array
    {
        return ['sent', 'blocked', 'gone', 'failed'];
    }

    /**
     * Only ever a counter column.
     *
     * The tally keys come from Outcome, not from user input, but this is
     * the one place a string reaches a raw SQL fragment and an allowlist
     * costs nothing.
     */
    private function isCounter(string $column): bool
    {
        return in_array($column, $this->counters(), true);
    }
}
