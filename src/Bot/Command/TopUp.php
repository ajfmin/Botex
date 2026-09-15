<?php

namespace Botex\Bot\Command;

use Botex\Bot\Action\Run;
use Botex\Bot\Middleware\NotBlocked;
use Botex\Bot\TopUp\Action\Start;
use Botex\Bot\TopUp\PaymentMethods;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Telegram\Bot;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Update;

/**
 * Where a customer adds money: their balance, and the ways to add to it.
 *
 * Core ships the list and knows nothing about paying. With no payment
 * extension installed -- or with every method switched off -- this says
 * so plainly rather than showing an empty screen, because "top up is not
 * available" is an answer and a blank list is not.
 *
 * Inline buttons, unlike the admin panel: this is a one-off choice
 * inside a single message, not a place anybody navigates around, and a
 * reply keyboard left behind after the choice is made would be clutter
 * a customer has no way to interpret.
 */
class TopUp implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private PaymentMethods $methods,
        private UserService $users,
        private WalletService $wallet
    ) {
    }

    public static function command(): string
    {
        return '/topup';
    }

    public static function button(): string
    {
        return 'Top up';
    }

    public static function middleware(): array
    {
        return [
            NotBlocked::class,
        ];
    }

    public function handle(Update $update): void
    {
        $telegramId = (int) $update->fromId();

        if ($telegramId === 0) {
            return;
        }

        $available = $this->methods->available();

        if ($available === []) {
            $this->bot->sendMessage('Adding funds is not available at the moment.')
                ->to($update->chatId())
                ->parseMode('HTML');

            return;
        }

        // The wallet keys off the internal id, so the customer has to
        // exist first. Someone reaching /topup before /start still gets one.
        $user = $this->users->findOrCreateUser($telegramId);

        $keyboard = Keyboard::inline();

        foreach ($available as $key => $method) {
            $keyboard->row(
                InlineButton::make($method::title())
                    // The key never travels in callback data; the token
                    // proves the press and the row holds the intent.
                    ->action(Run::core(Start::name(), ['method' => $key]))
            );
        }

        $this->bot->sendMessage(
            '<b>Top up</b>' . PHP_EOL . PHP_EOL
            . 'Balance: <b>' . $this->escape($this->wallet->balanceMoney((int) $user->id)->format()) . '</b>'
            . PHP_EOL . PHP_EOL
            . 'Choose how you would like to pay.'
        )
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($keyboard->build());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
