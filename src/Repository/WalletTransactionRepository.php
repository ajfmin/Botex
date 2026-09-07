<?php

namespace Botex\Repository;

use Botex\Model\WalletTransaction;
use Botex\Wallet\TransactionType;

/**
 * Append-only ledger access.
 *
 * There is no update or delete here on purpose: correcting a ledger means
 * writing a compensating entry, never editing history.
 */
class WalletTransactionRepository
{
    public function find(int $id): ?WalletTransaction
    {
        return WalletTransaction::find($id);
    }

    /**
     * Reads the row with an exclusive lock, so a concurrent refund of the
     * same debit cannot compute its remaining total at the same time.
     */
    public function lock(int $id): ?WalletTransaction
    {
        return WalletTransaction::where('id', $id)->lockForUpdate()->first();
    }

    public function findByIdempotencyKey(string $key): ?WalletTransaction
    {
        return WalletTransaction::where('idempotency_key', $key)->first();
    }

    public function create(array $attributes): WalletTransaction
    {
        return WalletTransaction::create($attributes);
    }

    /**
     * How much of a debit has already been refunded, as a positive number.
     *
     * Sums the refund entries pointing at it rather than tracking a
     * running total on the debit, so the answer is always derivable from
     * the ledger alone.
     */
    public function refundedTotal(int $transactionId): int
    {
        return (int) abs(
            (int) WalletTransaction::where('refunds_transaction_id', $transactionId)
                ->where('type', TransactionType::REFUND->value)
                ->sum('amount')
        );
    }

    /**
     * @return array<WalletTransaction> newest first
     */
    public function history(int $walletId, int $limit = 20, int $offset = 0): array
    {
        return WalletTransaction::where('wallet_id', $walletId)
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->all();
    }

    public function countFor(int $walletId): int
    {
        return WalletTransaction::where('wallet_id', $walletId)->count();
    }

    /**
     * Entries an extension linked to one of its own entities.
     *
     * @return array<WalletTransaction>
     */
    public function findByReference(string $type, string $id): array
    {
        return WalletTransaction::where('reference_type', $type)
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Reconciliation total: must equal the wallet balance. */
    public function sumFor(int $walletId): int
    {
        return (int) WalletTransaction::where('wallet_id', $walletId)->sum('amount');
    }
}
