<?php

namespace Botex\Service;

use Botex\Model\Wallet;
use Botex\Model\WalletTransaction;
use Botex\Repository\UserRepository;
use Botex\Repository\WalletRepository;
use Botex\Repository\WalletTransactionRepository;
use Botex\Support\Config;
use Botex\Support\Db;
use Botex\Wallet\Exception\DuplicateKeyRace;
use Botex\Wallet\Exception\IdempotencyConflict;
use Botex\Wallet\Exception\InsufficientFunds;
use Botex\Wallet\Exception\InvalidAmount;
use Botex\Wallet\Exception\NotRefundable;
use Botex\Wallet\Exception\TransactionNotFound;
use Botex\Wallet\Exception\WalletOwnerNotFound;
use Botex\Wallet\Money;
use Botex\Wallet\Reference;
use Botex\Wallet\TransactionType;
use Illuminate\Database\QueryException;

/**
 * The only place a balance changes.
 *
 * Takes the internal user id, not a telegram id, and knows nothing about
 * Telegram or any extension, so an extension can depend on it without the
 * wallet depending on the extension.
 *
 * Every operation runs in a transaction. Because Eloquent nests with
 * savepoints, calling these inside a caller's transaction joins it: a
 * purchase can debit and write its own order atomically, and a later
 * failure rolls the debit back too.
 */
class WalletService
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletTransactionRepository $transactions,
        private UserRepository $users,
        private Config $config
    ) {
    }

    /**
     * Runs several wallet operations as one unit.
     *
     * Use this when a debit and another write must succeed or fail
     * together, e.g. charging for an order and creating it.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function atomic(callable $work): mixed
    {
        return Db::transaction($work);
    }

    public function currency(): string
    {
        return (string) $this->config->get('wallet.currency', 'IRT');
    }

    public function scale(): int
    {
        return (int) $this->config->get('wallet.scale', 0);
    }

    public function money(int $minor): Money
    {
        return new Money($minor, $this->currency(), $this->scale());
    }

    /**
     * The user's wallet, created on first access.
     *
     * Verifies the user exists, so a typo cannot strand a balance under a
     * user id nobody can log in as.
     */
    public function wallet(int $userId): Wallet
    {
        $wallet = $this->wallets->findByUserId($userId);

        if ($wallet) {
            return $wallet;
        }

        if (!$this->users->findById($userId)) {
            throw new WalletOwnerNotFound($userId);
        }

        return $this->wallets->findOrCreate($userId, $this->currency());
    }

    public function balance(int $userId): int
    {
        return $this->wallet($userId)->balance;
    }

    public function balanceMoney(int $userId): Money
    {
        return $this->money($this->balance($userId));
    }

    public function canAfford(int $userId, int $amount): bool
    {
        return $this->balance($userId) >= $amount;
    }

    /**
     * Adds funds.
     *
     * @param int         $amount         positive, in minor units
     * @param string      $reason         short audit label
     * @param Reference|null $reference   optional pointer to a caller entity
     * @param string|null $idempotencyKey retry-safe unique key
     * @param array       $meta           extra context for the ledger
     */
    public function credit(
        int $userId,
        int $amount,
        string $reason,
        ?Reference $reference = null,
        ?string $idempotencyKey = null,
        array $meta = []
    ): WalletTransaction {
        return $this->apply(
            TransactionType::CREDIT,
            $userId,
            $amount,
            $reason,
            $reference,
            $idempotencyKey,
            $meta
        );
    }

    /**
     * Removes funds, refusing to go below zero.
     *
     * @throws InsufficientFunds when the balance would go negative
     */
    public function debit(
        int $userId,
        int $amount,
        string $reason,
        ?Reference $reference = null,
        ?string $idempotencyKey = null,
        array $meta = []
    ): WalletTransaction {
        return $this->apply(
            TransactionType::DEBIT,
            $userId,
            $amount,
            $reason,
            $reference,
            $idempotencyKey,
            $meta
        );
    }

    /**
     * Reverses a debit, wholly or partly, by writing a new entry.
     *
     * The original row is never modified. Passing null refunds whatever
     * remains unrefunded.
     *
     * @throws NotRefundable when the target is not a debit, or the total
     *                       would exceed what it charged
     */
    public function refund(
        int $transactionId,
        ?int $amount = null,
        string $reason = 'Refund',
        ?string $idempotencyKey = null,
        array $meta = []
    ): WalletTransaction {
        $this->guardReason($reason);

        try {
            return Db::transaction(function () use ($transactionId, $amount, $reason, $idempotencyKey, $meta) {
                // Locked first so two concurrent refunds of the same debit
                // cannot both read the same remaining total and both pass
                // the cap below.
                $original = $this->transactions->lock($transactionId)
                    ?? throw new TransactionNotFound($transactionId);

                if ($idempotencyKey !== null) {
                    $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);

                    if ($existing) {
                        return $this->reuse($existing, TransactionType::REFUND, $amount, $idempotencyKey);
                    }
                }

                if (!$original->isRefundable()) {
                    throw NotRefundable::wrongType($transactionId, $original->type());
                }

                $remaining = $original->absoluteAmount()
                    - $this->transactions->refundedTotal($transactionId);

                // Exhausted is a state problem, not a bad argument, so it
                // reports as NotRefundable either way.
                if ($remaining <= 0) {
                    throw NotRefundable::alreadyRefunded($transactionId);
                }

                // A null amount means "whatever is left", which is the
                // whole charge for a debit refunded once.
                $amount ??= $remaining;

                $this->guardAmount($amount);

                if ($amount > $remaining) {
                    throw NotRefundable::exceedsOriginal($transactionId, $remaining, $amount);
                }

                return $this->write(
                    type: TransactionType::REFUND,
                    wallet: $this->wallets->lock((int) $original->wallet_id)
                        ?? throw new WalletOwnerNotFound((int) $original->user_id),
                    amount: $amount,
                    reason: $reason,
                    reference: $original->reference(),
                    idempotencyKey: $idempotencyKey,
                    meta: $meta,
                    refundsTransactionId: (int) $original->id
                );
            });
        } catch (DuplicateKeyRace $race) {
            return $this->reuse($this->claimed($race), TransactionType::REFUND, $amount, $race->key);
        }
    }

    /**
     * Ledger entries, newest first.
     *
     * @return array<WalletTransaction>
     */
    public function history(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->transactions->history(
            (int) $this->wallet($userId)->id,
            max(1, $limit),
            max(0, $offset)
        );
    }

    public function historyCount(int $userId): int
    {
        return $this->transactions->countFor((int) $this->wallet($userId)->id);
    }

    /**
     * Every entry an extension linked to one of its own entities.
     *
     * @return array<WalletTransaction>
     */
    public function findByReference(Reference $reference): array
    {
        return $this->transactions->findByReference($reference->type, $reference->id);
    }

    public function transaction(int $transactionId): WalletTransaction
    {
        return $this->transactions->find($transactionId)
            ?? throw new TransactionNotFound($transactionId);
    }

    /**
     * Totals for the admin panel.
     *
     * @return array{wallets:int, total:int, formatted:string}
     */
    public function stats(): array
    {
        $total = $this->wallets->totalBalance();

        return [
            'wallets' => $this->wallets->count(),
            'total' => $total,
            'formatted' => $this->money($total)->format(),
        ];
    }

    /** How much of a debit is still refundable. */
    public function refundableAmount(int $transactionId): int
    {
        $transaction = $this->transaction($transactionId);

        if (!$transaction->isRefundable()) {
            return 0;
        }

        return $transaction->absoluteAmount()
            - $this->transactions->refundedTotal($transactionId);
    }

    /**
     * Shared path for credit and debit.
     *
     * Order matters: check idempotency, move the balance with a guarded
     * statement, then write the ledger entry from the balance the database
     * reports, so balance_after is never a value computed in PHP.
     */
    private function apply(
        TransactionType $type,
        int $userId,
        int $amount,
        string $reason,
        ?Reference $reference,
        ?string $idempotencyKey,
        array $meta
    ): WalletTransaction {
        $this->guardAmount($amount);
        $this->guardReason($reason);

        // Opened outside the transaction so a first-time wallet insert is
        // not rolled back by an insufficient-funds failure below.
        $wallet = $this->wallet($userId);

        try {
            return Db::transaction(function () use (
                $type, $wallet, $amount, $reason, $reference, $idempotencyKey, $meta
            ) {
                // Locked before the key lookup so the common case is
                // serialized per wallet and the race below stays rare.
                $locked = $this->wallets->lock((int) $wallet->id)
                    ?? throw new WalletOwnerNotFound((int) $wallet->user_id);

                if ($idempotencyKey !== null) {
                    $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);

                    if ($existing) {
                        return $this->reuse($existing, $type, $amount, $idempotencyKey);
                    }
                }

                return $this->write(
                    type: $type,
                    wallet: $locked,
                    amount: $amount,
                    reason: $reason,
                    reference: $reference,
                    idempotencyKey: $idempotencyKey,
                    meta: $meta
                );
            });
        } catch (DuplicateKeyRace $race) {
            return $this->reuse($this->claimed($race), $type, $amount, $race->key);
        }
    }

    /**
     * Moves the balance and appends the entry. Assumes an open transaction
     * and a locked wallet.
     */
    private function write(
        TransactionType $type,
        Wallet $wallet,
        int $amount,
        string $reason,
        ?Reference $reference,
        ?string $idempotencyKey,
        array $meta,
        ?int $refundsTransactionId = null
    ): WalletTransaction {
        $walletId = (int) $wallet->id;

        if ($type === TransactionType::DEBIT) {
            // The guard is in the WHERE clause, so no concurrent debit can
            // interleave between the check and the write.
            if ($this->wallets->decreaseIfSufficient($walletId, $amount) === 0) {
                throw new InsufficientFunds($this->wallets->balance($walletId), $amount);
            }
        } elseif ($this->wallets->increase($walletId, $amount) === 0) {
            throw new WalletOwnerNotFound((int) $wallet->user_id);
        }

        try {
            return $this->transactions->create([
                'wallet_id' => $walletId,
                'user_id' => (int) $wallet->user_id,
                'type' => $type->value,
                'amount' => $type->signed($amount),
                // read back, never computed in PHP
                'balance_after' => $this->wallets->balance($walletId),
                'reason' => $reason,
                'reference_type' => $reference?->type,
                'reference_id' => $reference?->id,
                'refunds_transaction_id' => $refundsTransactionId,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (QueryException $e) {
            // Lost a race on the unique idempotency key: a concurrent
            // request committed the same operation between our check and
            // this insert. Unwind so the balance move above is discarded,
            // then resolve to the winner's entry outside the transaction.
            if ($idempotencyKey !== null && $this->isDuplicateKey($e)) {
                throw new DuplicateKeyRace($idempotencyKey, $e);
            }

            throw $e;
        }
    }

    /**
     * Returns the original entry for a repeated key, once the parameters
     * are confirmed to match.
     *
     * A key reused with a different type or amount is a caller bug, and
     * silently returning the earlier result would hide it.
     */
    private function reuse(
        WalletTransaction $existing,
        TransactionType $type,
        ?int $amount,
        string $key
    ): WalletTransaction {
        if ($existing->type() !== $type) {
            throw new IdempotencyConflict(
                $key,
                "type {$type->value}, but it recorded a {$existing->type()->value}"
            );
        }

        if ($amount !== null && $existing->absoluteAmount() !== $amount) {
            throw new IdempotencyConflict(
                $key,
                "amount {$amount}, but it recorded {$existing->absoluteAmount()}"
            );
        }

        return $existing;
    }

    /**
     * The entry that won a race for an idempotency key.
     *
     * Read after the losing transaction unwound, so the winner's commit is
     * visible. If it still is not, the duplicate came from something other
     * than a wallet entry and the original database error is the truth.
     */
    private function claimed(DuplicateKeyRace $race): WalletTransaction
    {
        return $this->transactions->findByIdempotencyKey($race->key)
            ?? throw ($race->getPrevious() ?? $race);
    }

    /** Whether a query failure was an integrity violation on a unique index. */
    private function isDuplicateKey(QueryException $e): bool
    {
        // 23000 covers MySQL and SQLite; 23505 is Postgres.
        if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
            return true;
        }

        return (bool) preg_match('/duplicate|unique/i', $e->getMessage());
    }

    private function guardAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw InvalidAmount::notPositive($amount);
        }
    }

    private function guardReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw InvalidAmount::emptyReason();
        }
    }
}
