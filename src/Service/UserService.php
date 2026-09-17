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

            return $user;
        }

        // Somebody the last broadcast could not reach has turned up
        // again, so they are back in the audience. This is the only
        // evidence there is: Telegram never announces that a block was
        // lifted, and a blocked chat shows a RESTART button, which is
        // what brings them through here.
        if ($user->isUnreachable() && $this->userRepository->clearUnreachable($telegramId)) {
            $user->unreachable_at = null;
        }

        return $user;
    }

    /** People no broadcast can reach: they blocked the bot, or are gone. */
    public function unreachable(): int
    {
        return $this->userRepository->countUnreachable();
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
