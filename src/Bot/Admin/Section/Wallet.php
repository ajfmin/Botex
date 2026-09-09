<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\WalletAction;
use Botex\Service\WalletService;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Wallet totals, and the two buttons that adjust one balance by hand.
 *
 * Per-user balances stay in the Users panel; this one is the aggregate
 * view plus the operations, so a single screen never has to page through
 * every wallet to reach them.
 */
class Wallet implements AdminSectionInterface
{
    public function __construct(
        private Bot $bot,
        private WalletService $wallet
    ) {
    }

    public static function key(): string
    {
        return 'wallet';
    }

    public static function title(): string
    {
        return 'Wallet';
    }

    public function handle(Update $update): void
    {
        $stats = $this->wallet->stats();

        $lines = [
            '<b>Wallet</b>',
            '',
            'Opened: ' . $stats['wallets'],
            'Held balance: ' . $this->escape($stats['formatted']),
            'Currency: ' . $this->escape($this->wallet->currency())
                . ' (scale ' . $this->wallet->scale() . ')',
            '',
            '<i>Adjustments are written to the ledger with your id, and a '
                . 'debit can be refunded from the console.</i>',
        ];

        $keyboard = Keyboard::inline()
            ->row(
                InlineButton::callback(
                    WalletAction::title(WalletAction::CREDIT),
                    WalletAction::start(WalletAction::CREDIT)
                ),
                InlineButton::callback(
                    WalletAction::title(WalletAction::DEBIT),
                    WalletAction::start(WalletAction::DEBIT)
                )
            )
            ->row(InlineButton::callback('Back', Panel::HOME))
            ->build();

        $this->bot->editMessage(implode(PHP_EOL, $lines), (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($keyboard);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
