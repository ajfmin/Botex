<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Telegram\Update;

class Stats implements AdminSectionInterface
{
    public function __construct(
        private Panel $panel,
        private UserService $users,
        private WalletService $wallet
    ) {
    }

    public static function key(): string
    {
        return 'stats';
    }

    public static function title(): string
    {
        return 'Stats';
    }

    public function handle(Update $update): void
    {
        $stats = $this->users->stats();

        $lines = [
            '<b>User stats</b>',
            '',
            'Total: ' . $stats['total'],
            'Active: ' . $stats['active'],
            'Blocked: ' . $stats['blocked'],
            'New today: ' . $stats['today'],
            'New this week: ' . $stats['week'],
        ];

        $wallet = $this->wallet->stats();

        $lines[] = '';
        $lines[] = '<b>Wallets</b>';
        $lines[] = '';
        $lines[] = 'Opened: ' . $wallet['wallets'];
        $lines[] = 'Held balance: ' . htmlspecialchars($wallet['formatted'], ENT_QUOTES, 'UTF-8');

        $this->panel->show($update, implode(PHP_EOL, $lines), Panel::backKeyboard());
    }
}
