<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\BroadcastAction;
use Botex\Bot\Admin\Panel;
use Botex\Broadcast\BroadcastService;
use Botex\Broadcast\Status;
use Botex\Model\Broadcast as Row;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Update;

/**
 * Write a message once, and everyone gets it.
 *
 * The one screen in the panel that both starts something and watches it,
 * so both kinds of control are here and each is the shape the panel
 * reserves for it: reaching this screen is a keyboard label, and New,
 * Pause, Resume and Stop are inline buttons under the broadcast they act
 * on. Refresh is one too -- it redraws the same message rather than
 * pushing a new one down the chat, which is what you want when you are
 * watching a number climb.
 *
 * Note what is *not* here: no recipient picker, no schedule. The
 * audience is everyone the bot can still reach, and a send that has to
 * be composed now and delivered at nine tomorrow is a job, not a panel
 * screen. Keeping it to one decision is what makes the confirmation
 * meaningful.
 */
class Broadcast implements AdminSectionInterface
{
    /** Past broadcasts listed under the current one. */
    private const HISTORY = 5;

    public function __construct(
        private Panel $panel,
        private BroadcastService $broadcasts
    ) {
    }

    public static function key(): string
    {
        return 'bc';
    }

    public static function title(): string
    {
        return '📣 Broadcast';
    }

    public function handle(Update $update): void
    {
        $this->show($update);
    }

    /** Renders the screen, optionally with a line about what just happened. */
    public function show(Update $update, string $notice = ''): void
    {
        $running = $this->broadcasts->running() ?? $this->broadcasts->held();
        $audience = $this->broadcasts->audience();

        $lines = ['<b>Broadcast</b>', ''];

        if ($notice !== '') {
            $lines[] = $notice;
            $lines[] = '';
        }

        $lines[] = 'Reaches <b>' . number_format($audience) . '</b> '
            . ($audience === 1 ? 'person' : 'people')
            . ' at ' . $this->broadcasts->rate() . '/second.';

        $unreachable = $this->broadcasts->unreachable();

        if ($unreachable > 0) {
            // Said plainly rather than hidden: an admin comparing this
            // number to their user count deserves to know why it is
            // smaller before they go looking for a bug.
            $lines[] = '<i>' . number_format($unreachable) . ' left out - they blocked the bot '
                . 'or their account is gone.</i>';
        }

        $lines[] = '';

        $keyboard = Keyboard::inline();

        if ($running !== null) {
            foreach ($this->progress($running) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
            $this->controls($keyboard, $running);
        } else {
            $lines[] = 'Nothing is going out right now.';
            $lines[] = '';

            $keyboard->row(InlineButton::callback(
                '📣 New broadcast',
                BroadcastAction::to(BroadcastAction::NEW)
            ));
        }

        foreach ($this->history($running) as $line) {
            $lines[] = $line;
        }

        $this->panel->show($update, implode(PHP_EOL, $lines), $keyboard->build());
    }

    /**
     * The live figures for the one in flight.
     *
     * Every outcome, not just the flattering one. "8,912 delivered" on
     * its own reads as a healthy bot right up until someone notices the
     * audience was twelve thousand.
     *
     * @return array<string>
     */
    private function progress(Row $broadcast): array
    {
        $status = $broadcast->status();

        $lines = [
            $status->icon() . ' <b>#' . (int) $broadcast->id . '</b> '
                . $this->escape($status->label())
                . ' - ' . $broadcast->percent() . '%',
            $this->bar($broadcast->percent()),
            '',
            '✅ ' . number_format((int) $broadcast->sent)
                . '   🚫 ' . number_format((int) $broadcast->blocked)
                . '   👻 ' . number_format((int) $broadcast->gone)
                . '   ⚠️ ' . number_format((int) $broadcast->failed),
            number_format($broadcast->accounted()) . ' of '
                . number_format((int) $broadcast->total) . ' done, '
                . number_format($broadcast->remaining()) . ' to go',
        ];

        if ($broadcast->remaining() > 0 && $status === Status::SENDING) {
            $lines[] = 'About ' . $this->broadcasts->duration(
                $this->broadcasts->estimateFor($broadcast->remaining())
            ) . ' left.';
        }

        if ($broadcast->last_error) {
            $lines[] = '<i>Last error: '
                . $this->escape(mb_substr((string) $broadcast->last_error, 0, 120)) . '</i>';
        }

        return $lines;
    }

    /** Pause or resume, and stop; plus a way to watch it move. */
    private function controls(Keyboard $keyboard, Row $broadcast): void
    {
        $id = (int) $broadcast->id;

        $keyboard->row(
            $broadcast->status()->isResumable()
                ? InlineButton::callback('▶️ Resume', BroadcastAction::to(BroadcastAction::RESUME, $id))
                : InlineButton::callback('⏸ Pause', BroadcastAction::to(BroadcastAction::PAUSE, $id)),
            InlineButton::callback('✖️ Stop', BroadcastAction::to(BroadcastAction::STOP, $id))
        );

        $keyboard->row(InlineButton::callback(
            '🔄 Refresh',
            BroadcastAction::to(BroadcastAction::REFRESH, $id)
        ));
    }

    /**
     * The last few, so an admin can see what went out and how it landed.
     *
     * @return array<string>
     */
    private function history(?Row $running): array
    {
        $rows = $this->broadcasts->recent(self::HISTORY + 1);
        $lines = [];

        foreach ($rows as $row) {
            if ($running !== null && (int) $row->id === (int) $running->id) {
                continue;
            }

            if (count($lines) >= self::HISTORY) {
                break;
            }

            $lines[] = sprintf(
                '%s <b>#%d</b> %s delivered, %s blocked, %s failed%s',
                $row->status()->icon(),
                (int) $row->id,
                number_format((int) $row->sent),
                number_format((int) $row->blocked + (int) $row->gone),
                number_format((int) $row->failed),
                $row->finished_at ? ' - ' . $row->finished_at->format('M j, H:i') : ''
            );
        }

        return $lines === [] ? [] : array_merge(['<b>Earlier</b>'], $lines);
    }

    /** Ten blocks, because a phone is narrow and a number is above it. */
    private function bar(int $percent): string
    {
        $filled = (int) round($percent / 10);

        return str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
