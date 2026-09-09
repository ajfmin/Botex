<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Admin\WalletAction;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\ChoiceStep;
use Botex\Service\UserService;
use Botex\Service\WalletService;

/**
 * Last look before the balance moves.
 *
 * A mistyped amount is real money, so the panel restates what it is
 * about to do, next to the balance it is about to change. Cancel comes
 * from the prompt itself, so the only choice offered here is Confirm.
 */
class ConfirmAdjustment extends ChoiceStep
{
    public const YES = 'yes';

    public function __construct(
        private UserService $users,
        private WalletService $wallet
    ) {
    }

    public static function name(): string
    {
        return 'confirm';
    }

    public function question(Session $session): string
    {
        $action = (string) $session->get('action');
        $telegramId = (int) $session->get(AskTelegramId::name());
        $amount = (int) $session->get(AskAmount::name());

        $lines = [
            sprintf(
                '<b>%s %s</b> %s <code>%d</code>?',
                WalletAction::title($action),
                $this->escape($this->wallet->money($amount)->format()),
                WalletAction::preposition($action),
                $telegramId
            ),
            '',
            'Reason: ' . $this->escape((string) $session->get(AskReason::name())),
        ];

        $user = $this->users->find($telegramId);

        $lines[] = $user
            ? 'Current balance: ' . $this->escape(
                $this->wallet->balanceMoney((int) $user->id)->format()
            )
            : 'No user with that id any more; this will not go through.';

        return implode(PHP_EOL, $lines);
    }

    /** @return array<string, string> */
    public function choices(Session $session): array
    {
        return [self::YES => 'Confirm'];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
