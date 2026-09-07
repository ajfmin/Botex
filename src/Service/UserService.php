<?php

namespace Botex\Service;

use Botex\Model\User;
use Botex\Repository\UserRepository;

class UserService
{
    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    public function findOrCreateUser(int $telegramId): User
    {
        $user = $this->userRepository->findByTelegramId($telegramId);

        if (!$user) {
            $user = $this->userRepository->create([
                'telegram_id' => $telegramId,
                'status' => User::ACTIVE,
            ]);
        }

        return $user;
    }

    public function find(int $telegramId): ?User
    {
        return $this->userRepository->findByTelegramId($telegramId);
    }

    public function isBlocked(int $telegramId): bool
    {
        return (bool) $this->userRepository->findByTelegramId($telegramId)?->isBlocked();
    }

    /** @return bool false when there is no such user */
    public function block(int $telegramId): bool
    {
        return $this->userRepository->setStatus($telegramId, User::BLOCKED);
    }

    /** @return bool false when there is no such user */
    public function unblock(int $telegramId): bool
    {
        return $this->userRepository->setStatus($telegramId, User::ACTIVE);
    }

    /** @return array{total:int, active:int, blocked:int, today:int, week:int} */
    public function stats(): array
    {
        $midnight = new \DateTimeImmutable('today');

        return [
            'total' => $this->userRepository->count(),
            'active' => $this->userRepository->countByStatus(User::ACTIVE),
            'blocked' => $this->userRepository->countByStatus(User::BLOCKED),
            'today' => $this->userRepository->countSince($midnight),
            'week' => $this->userRepository->countSince($midnight->modify('-7 days')),
        ];
    }

    /** @return array<User> */
    public function latest(int $limit = 10): array
    {
        return $this->userRepository->latest($limit);
    }
}
