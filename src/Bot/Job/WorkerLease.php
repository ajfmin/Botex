<?php

namespace Botex\Bot\Job;

use Botex\Support\Schema;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * The "only one service runs" guarantee.
 *
 * A single row, id 1, naming whichever worker currently holds the system.
 * A second `jobs:work` finds the row held and refuses to start rather than
 * double-running every schedule.
 *
 * The hold expires, so a worker killed with -9 does not lock the bot out
 * of its own scheduler forever: once the lease lapses, the next start
 * takes over. Same acquire-by-conditional-UPDATE shape as claiming a job.
 */
class WorkerLease
{
    public const TABLE = 'job_worker';

    /** The only row. */
    private const ID = 1;

    /**
     * Takes the lease, or reports who holds it.
     *
     * @return bool false when another live worker owns it
     */
    public function acquire(string $workerId, int $seconds): bool
    {
        $now = Carbon::now();
        $this->ensureRow($now);

        // Free if nobody holds it, the holder's lease lapsed, or the
        // holder is this same worker renewing.
        return $this->table()
            ->where('id', self::ID)
            ->where(function ($query) use ($now, $workerId) {
                $query->whereNull('worker_id')
                    ->orWhere('worker_id', $workerId)
                    ->orWhere('lease_until', '<=', $now);
            })
            ->update([
                'worker_id' => $workerId,
                'host' => mb_substr((string) gethostname(), 0, 191),
                'pid' => getmypid() ?: 0,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'lease_until' => $now->copy()->addSeconds(max(1, $seconds)),
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Extends the lease, proving the worker is still alive.
     *
     * @return bool false when the lease was taken by someone else, which
     *              means this worker must stop
     */
    public function renew(string $workerId, int $seconds, int $ranJobs = 0): bool
    {
        $now = Carbon::now();

        $updated = $this->table()
            ->where('id', self::ID)
            ->where('worker_id', $workerId)
            ->update([
                'heartbeat_at' => $now,
                'lease_until' => $now->copy()->addSeconds(max(1, $seconds)),
                'ran' => new Expression('ran + ' . max(0, $ranJobs)),
                'updated_at' => $now,
            ]);

        if ($updated === 1) {
            return true;
        }

        // MySQL may report 0 affected rows when the values did not
        // actually change, even though this worker still owns the lease.
        return $this->table()
            ->where('id', self::ID)
            ->where('worker_id', $workerId)
            ->exists();
    }

    /** Hands the lease back on a clean shutdown. */
    public function release(string $workerId): bool
    {
        return $this->table()
            ->where('id', self::ID)
            ->where('worker_id', $workerId)
            ->update([
                'worker_id' => null,
                'lease_until' => null,
                'heartbeat_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    /** Clears the lease whoever holds it, for an admin unsticking things. */
    public function forceRelease(): void
    {
        $this->table()->where('id', self::ID)->update([
            'worker_id' => null,
            'lease_until' => null,
            'updated_at' => Carbon::now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $row = $this->table()->where('id', self::ID)->first();

        return $row === null ? null : (array) $row;
    }

    /** True when a worker holds the lease and it has not lapsed. */
    public function isHeld(): bool
    {
        $row = $this->current();

        if (!$row || ($row['worker_id'] ?? null) === null) {
            return false;
        }

        $until = $row['lease_until'] ?? null;

        return $until !== null && Carbon::parse($until)->isFuture();
    }

    /** Description for the panel: who is running, or that nobody is. */
    public function describe(): string
    {
        $row = $this->current();

        if (!$row || ($row['worker_id'] ?? null) === null) {
            return 'stopped';
        }

        $until = $row['lease_until'] ?? null;
        $live = $until !== null && Carbon::parse($until)->isFuture();

        return ($live ? 'running on ' : 'stale lease from ')
            . ($row['host'] ?? '?') . ' (pid ' . ($row['pid'] ?? '?') . ')';
    }

    public static function migrate(): void
    {
        Schema::createIfMissing(self::TABLE, function ($table) {
            $table->unsignedTinyInteger('id')->primary();
            // Null means free. Nothing else needs to be cleared for the
            // next worker to take over.
            $table->string('worker_id', 64)->nullable();
            $table->string('host', 191)->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedBigInteger('ran')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            // Past means abandoned, so a killed worker frees itself.
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
        });
    }

    /** Creates the singleton row once, ignoring a concurrent creator. */
    private function ensureRow(Carbon $now): void
    {
        if ($this->table()->where('id', self::ID)->exists()) {
            return;
        }

        try {
            $this->table()->insert([
                'id' => self::ID,
                'worker_id' => null,
                'ran' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            // Another worker inserted it first; the UPDATE decides who wins.
        }
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return Capsule::table(self::TABLE);
    }
}
