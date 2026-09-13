<?php

namespace Botex\Bot\Admin\Action;

use Botex\Bot\Action\RunContext;
use Botex\Bot\Action\RunnableInterface;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\Sections;
use Botex\Bot\Feeder;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Telegram\Update;

/**
 * Opens a panel section from the reply keyboard.
 *
 * The inline route has Callback\Admin\OpenSection; this is the same door
 * for a keyboard tap, which arrives as plain text and carries its meaning
 * in a stored binding rather than in callback data.
 *
 * Gated by IsAdmin like every other way into the panel. A label binding is
 * already scoped to one telegram id, but the gate is what makes that a
 * guarantee rather than an implementation detail -- a binding outlives the
 * keyboard it was made for, and an admin who stops being one must stop
 * having a panel.
 */
class OpenSection implements RunnableInterface
{
    public static function name(): string
    {
        return 'admin.section';
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public function __construct(
        private Panel $panel,
        private Sections $sections,
        private Feeder $feeder
    ) {
    }

    public function handle(Update $update, RunContext $context): void
    {
        $key = $context->string('key');

        // No key is the home screen: one label on the keyboard reopens the
        // panel itself rather than any section.
        if ($key === '') {
            $this->panel->show($update, '<b>Admin panel</b>', $this->panel->menu());

            return;
        }

        $section = $this->sections->find($key);

        if ($section === null) {
            // An extension was removed while its label was still on
            // somebody's keyboard.
            $this->panel->show($update, 'That panel is no longer available.', $this->panel->menu());

            return;
        }

        $this->feeder->make($section)->handle($update);
    }
}
