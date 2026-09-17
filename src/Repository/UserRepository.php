<?php

namespace Botex\Repository;

use Botex\Model\User;
use Illuminate\Support\Carbon;

class UserRepository
{

    public function findByTelegramId(int $telegramId): ?User
    {
        return User::where('telegram_id', $telegramId)->first();
    }

    /** Lookup by the internal id, which is what non-Telegram code should use. */
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function count(): int
    {
        return User::count();
    }

    public function countByStatus(string $status): int
    {
        return User::where('status', $status)->count();
    }

    /** Users first seen since the given moment. */
    public function countSince(\DateTimeInterface $since): int
    {
        return User::where('created_at', '>=', $since)->count();
    }

    public function setStatus(int $telegramId, string $status): bool
    {
        return User::where('telegram_id', $telegramId)->update(['status' => $status]) > 0;
    }

    /** @return array<User> newest first */
    public function latest(int $limit = 10): array
    {
        return User::orderByDesc('id')->limit($limit)->get()->all();
    }

    /**
     * How many people an announcement would actually reach.
     *
     * Blocked users are out because an admin blocked them, and a bot
     * that goes on announcing things to someone it refuses to serve is
     * not what "blocked" means. Unreachable ones are out because
     * Telegram already told us the message cannot arrive; spending rate
     * limit on them only makes every real recipient wait longer.
     */
    public function countReachable(): int
    {
        return $this->reachable()->count();
    }

    /**
     * The next slice of an audience, by ascending id.
     *
     * Keyset paging rather than an offset: a broadcast runs for as long
     * as it takes, users are created while it does, and OFFSET against a
     * growing table skips rows silently. The cursor is a real id, so
     * "everyone after this one" stays true however the table changes.
     *
     * @return array<User>
     */
    public function reachableAfter(int $afterId, int $limit): array
    {
        return $this->reachable()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** The highest reachable id, so a send knows where it ends. */
    public function lastReachableId(): int
    {
        return (int) ($this->reachable()->max('id') ?? 0);
    }

    /**
     * Records that Telegram will not deliver to this chat.
     *
     * Deliberately not `status = blocked`: that column is an admin's
     * decision about a user, and overwriting it here would mean somebody
     * blocking the bot silently banned themselves from it, which an
     * admin would then have to undo by hand without ever having done it.
     */
    public function markUnreachable(int $telegramId): bool
    {
        return User::where('telegram_id', $telegramId)
            ->whereNull('unreachable_at')
            ->update(['unreachable_at' => Carbon::now()]) > 0;
    }

    /**
     * Puts someone back in the audience.
     *
     * Called when they turn up again, which is the only evidence that
     * the block is over -- Telegram never says so. In practice that is
     * the RESTART button a blocked chat shows, which sends /start.
     */
    public function clearUnreachable(int $telegramId): bool
    {
        return User::where('telegram_id', $telegramId)
            ->whereNotNull('unreachable_at')
            ->update(['unreachable_at' => null]) > 0;
    }

    public function countUnreachable(): int
    {
        return User::whereNotNull('unreachable_at')->count();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function reachable()
    {
        return User::where('status', User::ACTIVE)->whereNull('unreachable_at');
    }
}
