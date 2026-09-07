<?php

namespace Botex\Repository;

use Botex\Model\Wallet;
use Illuminate\Database\QueryException;

/**
 * Balance row access.
 *
 * The two mutating methods are deliberately single statements. Doing the
 * arithmetic in SQL rather than in PHP means no read-modify-write window
 * exists for a concurrent request to slip into.
 */
class WalletRepository
{
    public function find(int $walletId): ?Wallet
    {
        return Wallet::find($walletId);
    }

    public function findByUserId(int $userId): ?Wallet
    {
        return Wallet::where('user_id', $userId)->first();
    }

    /**
     * Opens a wallet for a user, tolerating a concurrent opener.
     *
     * Two simultaneous first-time operations would both see no wallet, so
     * the unique index on user_id decides and the loser re-reads.
     */
    public function findOrCreate(int $userId, string $currency): Wallet
    {
        $wallet = $this->findByUserId($userId);

        if ($wallet) {
            return $wallet;
        }

        try {
            return Wallet::create([
                'user_id' => $userId,
                'balance' => 0,
                'currency' => $currency,
            ]);
        } catch (QueryException $e) {
            $wallet = $this->findByUserId($userId);

            if (!$wallet) {
                throw $e;
            }

            return $wallet;
        }
    }

    /**
     * Reads the row with an exclusive lock, serialising everything else
     * that touches this wallet until the transaction ends.
     *
     * Must be called inside a transaction to mean anything. On MySQL this
     * is SELECT ... FOR UPDATE; SQLite ignores it and serialises writes
     * at the database level instead.
     */
    public function lock(int $walletId): ?Wallet
    {
        return Wallet::where('id', $walletId)->lockForUpdate()->first();
    }

    public function balance(int $walletId): int
    {
        return (int) Wallet::where('id', $walletId)->value('balance');
    }

    /**
     * Adds to the balance in one statement.
     *
     * @return int rows affected; 0 means the wallet vanished
     */
    public function increase(int $walletId, int $amount): int
    {
        // Eloquent's increment already touches updated_at.
        return Wallet::where('id', $walletId)->increment('balance', $amount);
    }

    /**
     * Subtracts from the balance only if it stays non-negative.
     *
     * The balance >= amount guard lives in the WHERE clause, so the check
     * and the write are one indivisible operation. This is what actually
     * prevents a negative balance under simultaneous debits: the second
     * request matches no rows and is refused, whatever it read earlier.
     *
     * @return int rows affected; 0 means insufficient funds
     */
    public function decreaseIfSufficient(int $walletId, int $amount): int
    {
        return Wallet::where('id', $walletId)
            ->where('balance', '>=', $amount)
            ->decrement('balance', $amount);
    }

    public function count(): int
    {
        return Wallet::count();
    }

    /** Sum of every balance, for admin reporting. */
    public function totalBalance(): int
    {
        return (int) Wallet::sum('balance');
    }
}
