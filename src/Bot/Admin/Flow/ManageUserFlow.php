<?php

namespace Botex\Bot\Admin\Flow;

use Botex\Bot\Admin\Flow\Step\AskTelegramId;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\UserAction;
use Botex\Bot\Conversation\FlowInterface;
use Botex\Bot\Conversation\Session;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Support\Config;
use Botex\Telegram\Bot;

/**
 * Asks for a telegram id, then checks, blocks or unblocks that user
 * depending on the action seeded when the flow started.
 */
class ManageUserFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private UserService $users,
        private WalletService $wallet,
        private Config $config
    ) {
    }

    public static function name(): string
    {
        return 'admin.manage_user';
    }

    public static function steps(): array
    {
        return [
            AskTelegramId::class,
        ];
    }

    public function complete(Session $session): void
    {
        $action = (string) $session->get('action');
        $telegramId = (int) $session->get(AskTelegramId::name());

        $this->bot->sendMessage($this->run($action, $telegramId))
            ->to($session->telegramId)
            ->parseMode('HTML')
            ->replyMarkup(Panel::backKeyboard());
    }

    private function run(string $action, int $telegramId): string
    {
        $user = $this->users->find($telegramId);

        if (!$user) {
            return 'No user found with id <code>' . $telegramId . '</code>.';
        }

        return match ($action) {
            UserAction::CHECK => $this->describe($telegramId),
            UserAction::BLOCK => $this->block($telegramId),
            UserAction::UNBLOCK => $this->unblock($telegramId),
            default => 'Unknown action.',
        };
    }

    private function describe(int $telegramId): string
    {
        $user = $this->users->find($telegramId);

        $lines = [
            '<b>User</b> <code>' . $telegramId . '</code>',
            '',
            'Status: ' . htmlspecialchars((string) ($user->status ?? 'unknown'), ENT_QUOTES, 'UTF-8'),
            'Joined: ' . ($user->created_at ? $user->created_at->format('Y-m-d H:i') : 'unknown'),
        ];

        if ($user->phone_number) {
            $lines[] = 'Phone: ' . htmlspecialchars((string) $user->phone_number, ENT_QUOTES, 'UTF-8');
        }

        $lines[] = 'Balance: ' . htmlspecialchars(
            $this->wallet->balanceMoney((int) $user->id)->format(),
            ENT_QUOTES,
            'UTF-8'
        );

        $entries = $this->wallet->history((int) $user->id, 5);

        if ($entries) {
            $lines[] = '';
            $lines[] = '<b>Recent wallet activity</b>';

            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '%s%s - %s',
                    $entry->amount > 0 ? '+' : '-',
                    $this->wallet->money($entry->absoluteAmount())->amount(),
                    htmlspecialchars((string) $entry->reason, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Admins are exempt, so a mistyped id cannot lock an admin out of
     * the panel that would let them undo it.
     */
    private function block(int $telegramId): string
    {
        if (in_array($telegramId, (array) $this->config->get('admins', []), true)) {
            return 'That user is an admin and cannot be blocked.';
        }

        return $this->users->block($telegramId)
            ? 'Blocked <code>' . $telegramId . '</code>.'
            : 'Could not block <code>' . $telegramId . '</code>.';
    }

    private function unblock(int $telegramId): string
    {
        return $this->users->unblock($telegramId)
            ? 'Unblocked <code>' . $telegramId . '</code>.'
            : 'Could not unblock <code>' . $telegramId . '</code>.';
    }
}
