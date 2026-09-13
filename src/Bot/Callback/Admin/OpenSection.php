<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\Sections;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Feeder;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Routes admin:s:<key> to the section that owns it.
 *
 * Extension sections arrive here too, so the admin gate below is the
 * single place permission is enforced for every panel.
 */
class OpenSection implements CallbackInterface, MatchesCallback
{
    public function __construct(
        private Bot $bot,
        private Panel $panel,
        private Sections $sections,
        private Feeder $feeder
    ) {
    }

    public static function callback(): string
    {
        return Panel::SECTION;
    }

    public static function matches(string $data): bool
    {
        return str_starts_with($data, Panel::SECTION);
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function handle(Update $update): void
    {
        $callbackId = $update->callbackId();

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId);
        }

        $key = substr((string) $update->callbackData(), strlen(Panel::SECTION));
        $section = $this->sections->find($key);

        if ($section === null) {
            $this->bot->sendMessage('That panel is no longer available.')
                ->to($update->chatId())
                ->replyMarkup(Panel::backKeyboard());

            return;
        }

        // An inline press edits the message it came from, and an edited
        // message cannot carry a reply keyboard -- so the keyboard swap
        // is its own line, and only when it would actually change.
        $this->panel->useMenu($update, $key);

        $this->feeder->make($section)->handle($update);
    }
}
