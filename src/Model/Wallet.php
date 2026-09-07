<?php

namespace Botex\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's balance, in minor units.
 *
 * The balance is denormalized from the ledger for read speed. It stays
 * authoritative because every change goes through WalletService, and the
 * test suite asserts SUM(transactions.amount) == balance.
 */
class Wallet extends Model
{
    protected $table = 'wallets';

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'balance' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function has(int $amount): bool
    {
        return $this->balance >= $amount;
    }
}
