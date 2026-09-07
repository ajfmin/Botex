<?php

namespace Botex\Repository;

use Botex\Model\User;

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
}
