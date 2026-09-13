<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\UserAction;
use Botex\Service\UserService;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Update;

class Users implements AdminSectionInterface
{
    public function __construct(
        private Panel $panel,
        private UserService $users
    ) {
    }

    public static function key(): string
    {
        return 'users';
    }

    public static function title(): string
    {
        return 'Users';
    }

    public function handle(Update $update): void
    {
        $latest = $this->users->latest(10);
        $lines = ['<b>Users</b>', ''];

        if (!$latest) {
            $lines[] = 'No users yet.';
        }

        foreach ($latest as $user) {
            $lines[] = sprintf(
                '%s <code>%d</code>%s',
                $user->isBlocked() ? '[blocked]' : '[active]',
                (int) $user->telegram_id,
                $user->created_at ? ' - ' . $user->created_at->format('Y-m-d') : ''
            );
        }

        $lines[] = '';
        $lines[] = '<i>Showing the 10 newest.</i>';

        $keyboard = Keyboard::inline()
            ->row(
                InlineButton::callback('Check', UserAction::start('check')),
                InlineButton::callback('Block', UserAction::start('block')),
                InlineButton::callback('Unblock', UserAction::start('unblock'))
            )
            ->row(InlineButton::callback('Back', Panel::HOME))
            ->build();

        $this->panel->show($update, implode(PHP_EOL, $lines), $keyboard);
    }
}
