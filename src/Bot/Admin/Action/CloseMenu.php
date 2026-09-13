<?php

namespace Botex\Bot\Admin\Action;

use Botex\Bot\Action\RunContext;
use Botex\Bot\Action\RunnableInterface;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Takes the admin keyboard away.
 *
 * A reply keyboard is not a message: it stays under the text box until
 * something removes it, including while the admin is talking to a
 * customer in the same chat. So there has to be a way out that is as
 * quick as the way in, and /admin puts it straight back.
 */
class CloseMenu implements RunnableInterface
{
    public static function name(): string
    {
        return 'admin.close';
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function __construct(
        private Bot $bot
    ) {
    }

    public function handle(Update $update, RunContext $context): void
    {
        $this->bot->sendMessage('Admin menu closed. /admin brings it back.')
            ->to($update->chatId())
            ->replyMarkup(Panel::hideKeyboard());
    }
}
