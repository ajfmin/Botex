<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Extension\Registry;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Read-only view of installed extensions. Enable, disable and remove
 * stay in the CLI, so a compromised admin account cannot delete files.
 */
class Extensions implements AdminSectionInterface
{
    public function __construct(
        private Bot $bot,
        private Registry $registry
    ) {
    }

    public static function key(): string
    {
        return 'ext';
    }

    public static function title(): string
    {
        return 'Extensions';
    }

    public function handle(Update $update): void
    {
        $lines = ['<b>Extensions</b>', ''];
        $all = $this->registry->all();

        if (!$all) {
            $lines[] = 'None installed.';
        }

        foreach ($all as $manifest) {
            $status = $this->registry->isEnabled($manifest->slug) ? 'on' : 'off';

            $lines[] = sprintf(
                '%s <b>%s</b> %s [%s]',
                $status === 'on' ? '+' : '-',
                $this->escape($manifest->name),
                $this->escape($manifest->version),
                $this->escape($manifest->slug)
            );
        }

        foreach ($this->registry->errors() as $slug => $error) {
            $lines[] = '! ' . $this->escape((string) $slug) . ': ' . $this->escape($error);
        }

        $lines[] = '';
        $lines[] = '<i>Manage with php bin/console ext:list</i>';

        $this->bot->editMessage(implode(PHP_EOL, $lines), (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup(Panel::backKeyboard());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
