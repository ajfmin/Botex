<?php

namespace Botex\Bot\TopUp;

use Botex\Model\TopUp;
use Botex\Repository\TopUpRepository;
use Botex\Service\WalletService;
use Botex\Wallet\Reference;

/**
 * The one door money comes in through.
 *
 * A payment extension that has satisfied itself a customer really paid
 * calls this instead of `WalletService::credit()` directly. Two things
 * happen as one: the balance moves, and a `topups` row records which
 * method moved it. Doing them separately is how a bot ends up with a
 * report that disagrees with its own ledger -- a credit written but not
 * attributed, or an attribution for money that never arrived.
 *
 * The idempotency key is the extension's to choose and MUST be derived
 * from whatever it is settling -- an order id, a receipt id, a gateway
 * payment id -- never generated per attempt. That is what makes a
 * redelivered webhook or a retried job safe: the wallet returns the
 * original entry instead of moving the balance again, and this notices
 * and does not write a second `topups` row for it either.
 */
class TopUpService
{
    /** Reference type every top-up entry carries in the ledger. */
    public const REFERENCE = 'topup';

    public function __construct(
        private WalletService $wallet,
        private TopUpRepository $topups,
        private PaymentMethods $methods
    ) {
    }

    /**
     * Credits a customer and records where it came from.
     *
     * @param  int    $userId         internal user id, never a telegram id
     * @param  int    $amount         wallet minor units, positive
     * @param  string $method         a registered payment method key
     * @param  string $idempotencyKey derived from what is being settled
     * @param  string $reference      the extension's own id for this payment
     * @param  array<string, mixed> $meta anything the report need not read
     * @throws \InvalidArgumentException when the method is not registered
     * @throws \Botex\Wallet\Exception\WalletException on a refused credit
     */
    public function credit(
        int $userId,
        int $amount,
        string $method,
        string $idempotencyKey,
        string $reference = '',
        string $reason = 'Top-up',
        array $meta = []
    ): TopUp {
        // Refused rather than recorded: an unknown key would produce a
        // row the report cannot attribute and an admin cannot switch off.
        if (!$this->methods->has($method)) {
            throw new \InvalidArgumentException("Unknown payment method '{$method}'.");
        }

        return $this->wallet->atomic(function () use (
            $userId,
            $amount,
            $method,
            $idempotencyKey,
            $reference,
            $reason,
            $meta
        ) {
            $entry = $this->wallet->credit(
                userId: $userId,
                amount: $amount,
                reason: $reason,
                reference: $reference === ''
                    ? null
                    : Reference::to(self::REFERENCE . ':' . $method, $reference),
                idempotencyKey: $idempotencyKey,
            );

            // A repeat of the same key returns the original entry rather
            // than moving the balance, so the row that entry already has
            // is the honest answer -- writing a second one would double
            // the figure on a report the balance never doubled.
            $existing = TopUp::where('transaction_id', (int) $entry->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->topups->create([
                'user_id' => $userId,
                'method' => $method,
                'amount' => $amount,
                'transaction_id' => (int) $entry->id,
                'reference' => $reference === '' ? null : $reference,
                'meta' => $meta === [] ? null : $meta,
            ]);
        });
    }
}
