<?php

namespace Botex\Bot\TopUp\Action;

use Botex\Bot\Action\RunContext;
use Botex\Bot\Action\RunnableInterface;
use Botex\Bot\Middleware\NotBlocked;
use Botex\Bot\TopUp\MethodState;
use Botex\Bot\TopUp\PaymentMethods;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Hands a customer over to the payment method they picked.
 *
 * A Run action rather than a plain callback because the method key would
 * otherwise travel through Telegram in the button, and callback data is
 * user-supplied: anyone can send arbitrary bytes back. Here the key
 * lives in the stored row the token proves, and is still checked against
 * the allowlist before anything is built.
 *
 * Re-checked rather than trusted, because a button outlives the screen
 * it was drawn on: a method can be switched off, misconfigured, or have
 * its extension removed between the customer seeing it and pressing it.
 */
class Start implements RunnableInterface
{
    public function __construct(
        private PaymentMethods $methods,
        private MethodState $state,
        private Bot $bot
    ) {
    }

    public static function name(): string
    {
        return 'topup.start';
    }

    public static function middleware(): array
    {
        return [
            NotBlocked::class,
        ];
    }

    public function handle(Update $update, RunContext $context): void
    {
        $key = $context->string('method');
        $method = $this->methods->make($key);

        if ($method === null || !$this->state->isEnabled($key) || !$method->isConfigured()) {
            $this->bot->sendMessage('This payment method is not available right now.')
                ->to($update->chatId());

            return;
        }

        $method->start($update);
    }
}
