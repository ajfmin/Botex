<?php

namespace Botex\Model;

use Botex\Wallet\Money;
use Botex\Wallet\Reference;
use Botex\Wallet\TransactionType;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable ledger entry.
 *
 * Rows are append-only: a refund inserts a new entry pointing back at the
 * debit it reverses rather than editing it, so history stays auditable.
 *
 * amount is signed, positive for credits and refunds, negative for
 * debits, which makes SUM(amount) reconcile against the wallet balance.
 */
class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'balance_after',
        'reason',
        'reference_type',
        'reference_id',
        'refunds_transaction_id',
        'idempotency_key',
        'meta',
    ];

    protected $casts = [
        'wallet_id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'integer',
        'balance_after' => 'integer',
        'refunds_transaction_id' => 'integer',
        'meta' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * The debit this entry refunds, when it is a refund.
     *
     * Not named original(), which collides with Eloquent's own dirty
     * tracking.
     */
    public function refunded()
    {
        return $this->belongsTo(self::class, 'refunds_transaction_id');
    }

    public function type(): TransactionType
    {
        return TransactionType::from((string) $this->type);
    }

    /** Always positive, whichever direction the entry moves. */
    public function absoluteAmount(): int
    {
        return abs((int) $this->amount);
    }

    public function money(string $currency = 'IRT', int $scale = 0): Money
    {
        return new Money((int) $this->amount, $currency, $scale);
    }

    public function reference(): ?Reference
    {
        if ($this->reference_type === null || $this->reference_id === null) {
            return null;
        }

        return new Reference((string) $this->reference_type, (string) $this->reference_id);
    }

    public function isRefundable(): bool
    {
        return $this->type() === TransactionType::DEBIT;
    }
}
