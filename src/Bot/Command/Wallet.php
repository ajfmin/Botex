<?php

namespace Botex\Bot\Command;

use Botex\Bot\Middleware\NotBlocked;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Shows the caller their balance and recent entries.
 *
 * Read only: topping up is a payment concern, so it belongs to whichever
 * extension owns the gateway.
 */
class Wallet implements CommandInterface
{
    private const HISTORY = 5;

    public function __construct(
        private Bot $bot,
        private UserService $users,
        private WalletService $wallet
    ) {
    }

    public static function command(): string
    {
        return '/wallet';
    }

    public static function button(): string
    {
        return 'Wallet';
    }

    public static function middleware(): array
    {
        return [
            NotBlocked::class,
        ];
    }

    public function handle(Update $update): void
    {
        // The wallet keys off the internal id, so the user has to exist
        // first. Someone reaching /wallet before /start still gets one.
        $user = $this->users->findOrCreateUser($update->fromId());
        $userId = (int) $user->id;

        $lines = [
            '<b>Wallet</b>',
            '',
            'Balance: <b>' . $this->escape($this->wallet->balanceMoney($userId)->format()) . '</b>',
        ];

        $entries = $this->wallet->history($userId, self::HISTORY);

        if (!$entries) {
            $lines[] = '';
            $lines[] = 'No transactions yet.';
        } else {
            $lines[] = '';
            $lines[] = '<b>Recent</b>';

            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '%s%s - %s%s',
                    $entry->amount > 0 ? '+' : '-',
                    $this->wallet->money($entry->absoluteAmount())->amount(),
                    $this->escape((string) $entry->reason),
                    $entry->created_at ? ' <i>(' . $entry->created_at->format('Y-m-d') . ')</i>' : ''
                );
            }

            $total = $this->wallet->historyCount($userId);

            if ($total > self::HISTORY) {
                $lines[] = '';
                $lines[] = '<i>Showing ' . self::HISTORY . ' of ' . $total . '.</i>';
            }
        }

        $this->bot->sendMessage(implode(PHP_EOL, $lines))
            ->to($update->chatId())
            ->parseMode('HTML');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
