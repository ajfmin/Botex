<?php

namespace Botex\Bot\Admin\Flow;

use Botex\Bot\Admin\Flow\Step\AskAmount;
use Botex\Bot\Admin\Flow\Step\AskReason;
use Botex\Bot\Admin\Flow\Step\AskTelegramId;
use Botex\Bot\Admin\Flow\Step\ConfirmAdjustment;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\WalletAction;
use Botex\Bot\Conversation\FlowInterface;
use Botex\Bot\Conversation\Session;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;
use Botex\Wallet\Exception\InsufficientFunds;
use Botex\Wallet\Exception\WalletException;

/**
 * Asks who, how much and why, then moves that balance in the direction
 * seeded when the flow started.
 *
 * Both directions go through WalletService like any other caller, so a
 * panel adjustment lands in the ledger next to an extension's charge and
 * is refundable the same way.
 */
class AdjustBalanceFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private UserService $users,
        private WalletService $wallet,
        private Logger $log
    ) {
    }

    public static function name(): string
    {
        return 'admin.adjust_balance';
    }

    public static function steps(): array
    {
        return [
            AskTelegramId::class,
            AskAmount::class,
            AskReason::class,
            ConfirmAdjustment::class,
        ];
    }

    public function complete(Session $session): void
    {
        $this->bot->sendMessage($this->run($session))
            ->to($session->telegramId)
            ->parseMode('HTML')
            ->replyMarkup(Panel::backKeyboard());
    }

    private function run(Session $session): string
    {
        $action = (string) $session->get('action');

        if (!WalletAction::valid($action)) {
            return 'Unknown action.';
        }

        if ($session->get(ConfirmAdjustment::name()) !== ConfirmAdjustment::YES) {
            return 'Nothing was changed.';
        }

        $telegramId = (int) $session->get(AskTelegramId::name());
        $amount = (int) $session->get(AskAmount::name());
        $reason = trim((string) $session->get(AskReason::name()));

        // Read again rather than trusting the id that passed the first
        // step: several messages have gone by since, and the wallet keys
        // off the internal id, never the telegram one.
        $user = $this->users->find($telegramId);

        if (!$user) {
            return 'No user found with id <code>' . $telegramId . '</code>.';
        }

        try {
            $entry = $action === WalletAction::DEBIT
                ? $this->wallet->debit((int) $user->id, $amount, $reason, meta: $this->meta($session))
                : $this->wallet->credit((int) $user->id, $amount, $reason, meta: $this->meta($session));
        } catch (InsufficientFunds $e) {
            return implode(PHP_EOL, [
                'Not enough funds in <code>' . $telegramId . '</code>.',
                '',
                'Balance: ' . $this->escape($this->wallet->money($e->balance)->format()),
                'Short by: ' . $this->escape($this->wallet->money($e->shortfall())->format()),
            ]);
        } catch (WalletException $e) {
            $this->log->error('Admin balance adjustment refused', [
                'admin' => $session->telegramId,
                'telegram_id' => $telegramId,
                'action' => $action,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return 'That adjustment was refused: ' . $this->escape($e->getMessage());
        }

        // Worth a line in the log either way: this is the one path where a
        // balance moves because a person said so rather than because code
        // charged for something.
        $this->log->info('Admin adjusted a balance', [
            'admin' => $session->telegramId,
            'telegram_id' => $telegramId,
            'action' => $action,
            'amount' => $amount,
            'transaction' => (int) $entry->id,
        ]);

        return implode(PHP_EOL, [
            sprintf(
                '<b>%s</b> <code>%d</code>.',
                WalletAction::past($action),
                $telegramId
            ),
            '',
            'Amount: ' . $this->escape($this->wallet->money($entry->absoluteAmount())->format()),
            'Reason: ' . $this->escape($reason),
            'New balance: ' . $this->escape(
                $this->wallet->money((int) $entry->balance_after)->format()
            ),
            'Entry: <code>#' . (int) $entry->id . '</code>',
        ]);
    }

    /**
     * Who made the change, so the ledger says more than "manual" when
     * someone asks about this entry months later.
     *
     * @return array<string, mixed>
     */
    private function meta(Session $session): array
    {
        return [
            'source' => 'admin_panel',
            'admin_telegram_id' => $session->telegramId,
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
