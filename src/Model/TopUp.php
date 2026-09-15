<?php

namespace Botex\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * One completed top-up, and which method brought it in.
 *
 * The wallet ledger already records that money arrived. This records
 * *how*, which the ledger deliberately does not know: a wallet entry has
 * a free-form reference an extension chose, and asking a report to
 * reverse-engineer payment methods out of those strings would make the
 * report depend on every extension's private naming.
 *
 * Written in the same transaction as the credit it describes, so the two
 * cannot disagree. `transaction_id` points back at that entry, which is
 * the audit trail: this table says what happened, the ledger says what
 * the balance did about it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property int $amount
 * @property int|null $transaction_id
 * @property string|null $reference
 */
class TopUp extends Model
{
    protected $table = 'topups';

    protected $fillable = [
        'user_id',
        'method',
        'amount',
        'transaction_id',
        'reference',
        'meta',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'amount' => 'integer',
        'transaction_id' => 'integer',
        'meta' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'transaction_id');
    }
}
