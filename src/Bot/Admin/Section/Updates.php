<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Botex;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Botex\Update\Availability;

/**
 * Read-only view of available updates.
 *
 * There is deliberately no button that installs anything. Two reasons, and
 * the second is the one that decided it: a webhook must not spend its time
 * downloading archives, and an admin here is whoever holds a Telegram
 * account, so a button that writes PHP files would turn a stolen session
 * into remote code execution. The commands are printed instead, to be run
 * by someone who is already on the host.
 *
 * Everything shown comes from the cache Availability reads, so this never
 * makes Telegram wait on the archive.
 */
class Updates implements AdminSectionInterface
{
    public function __construct(
        private Bot $bot,
        private Availability $availability
    ) {
    }

    public static function key(): string
    {
        return 'upd';
    }

    public static function title(): string
    {
        return 'Updates';
    }

    public function handle(Update $update): void
    {
        $lines = ['<b>Updates</b>', ''];
        $lines[] = 'Running Botex <b>' . $this->escape(Botex::VERSION) . '</b>';
        $lines[] = '';

        if (!$this->availability->configured()) {
            $lines[] = 'No archive is configured.';
            $lines[] = '';
            $lines[] = '<i>Set archive.url in config/config.php to check for updates.</i>';

            $this->send($lines, $update);

            return;
        }

        if (!$this->availability->known()) {
            // Never reported as "up to date": nothing has looked yet, and
            // saying otherwise would be a guess dressed as a fact.
            $lines[] = 'Not checked yet.';
            $lines[] = '';
            $lines[] = '<i>Run php bin/console core:check to look.</i>';

            $this->send($lines, $update);

            return;
        }

        $core = $this->availability->core();
        $extensions = $this->availability->extensions();

        if ($core === null && $extensions === []) {
            $lines[] = 'Everything is up to date.';
            $this->appendConflicts($lines);
            $this->send($lines, $update);

            return;
        }

        if ($core !== null) {
            $lines[] = 'Core: ' . $this->escape(Botex::VERSION)
                . ' &#8594; <b>' . $this->escape($core->version) . '</b>';

            if ($core->changelog !== '') {
                $lines[] = '<i>' . $this->escape($this->trim($core->changelog, 200)) . '</i>';
            }

            $lines[] = '';
        }

        if ($extensions !== []) {
            $lines[] = '<b>Extensions</b>';

            foreach ($extensions as $entry) {
                $lines[] = sprintf(
                    '%s %s &#8594; <b>%s</b>',
                    $this->escape($entry['name']),
                    $this->escape($entry['installed']),
                    $this->escape($entry['available'])
                );
            }

            $lines[] = '';
        }

        $this->appendConflicts($lines);

        $lines[] = '<b>Apply on the host</b>';

        if ($core !== null) {
            $lines[] = '<code>php bin/console core:update</code>';
        }

        if ($extensions !== []) {
            $lines[] = '<code>php bin/console ext:update --all</code>';
        }

        $lines[] = '';
        $lines[] = '<i>Shown from the last check, so it may be a little behind.</i>';

        $this->send($lines, $update);
    }

    /**
     * Warns about edited core files before an update is attempted.
     *
     * Worth saying here rather than leaving it to the CLI's refusal: the
     * person reading the panel is often the one who will run the command,
     * and knowing in advance beats a wall of conflicts later.
     *
     * @param array<string> $lines
     */
    private function appendConflicts(array &$lines): void
    {
        if (!$this->availability->baselined()) {
            $lines[] = 'No baseline recorded, so local edits cannot be detected.';
            $lines[] = '<code>php bin/console core:adopt</code>';
            $lines[] = '';

            return;
        }

        $conflicts = $this->availability->conflicts();

        if ($conflicts === []) {
            return;
        }

        $lines[] = sprintf(
            '%d core file(s) edited locally. A core update will stop rather than',
            count($conflicts)
        );
        $lines[] = 'overwrite them. See <code>php bin/console core:diff</code>';
        $lines[] = '';
    }

    /** @param array<string> $lines */
    private function send(array $lines, Update $update): void
    {
        $this->bot->editMessage(implode(PHP_EOL, $lines), (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup(Panel::backKeyboard());
    }

    private function trim(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 1) . '…'
            : $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
