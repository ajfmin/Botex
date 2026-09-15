<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\HasSubMenu;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Callback\Admin\ToggleMethod;
use Botex\Bot\TopUp\Finance;
use Botex\Service\WalletService;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Update;

/**
 * The money screen: what came in, and which routes it may come in by.
 *
 * Two screens and they are different kinds of thing, which is why they
 * are separate rather than one long page. The **report** is read; the
 * **methods** screen is acted on. Both are keyboard labels because both
 * open a screen, and the only inline buttons in here are the toggles --
 * which act on the row they sit next to.
 */
class TopUp implements AdminSectionInterface, HasSubMenu
{
    /** Days the Telegram report covers. Short: it is read on a phone. */
    private const WINDOW = 30;

    public function __construct(
        private Panel $panel,
        private Finance $finance,
        private WalletService $wallet
    ) {
    }

    public static function key(): string
    {
        return 'topup';
    }

    public static function title(): string
    {
        return 'Top-ups';
    }

    /** @return array<string, string> */
    public static function menuItems(): array
    {
        return [
            '💳 Payment methods' => 'methods',
        ];
    }

    public function openScreen(Update $update, string $screen): void
    {
        match ($screen) {
            'methods' => $this->methods($update),
            default => $this->handle($update),
        };
    }

    public function handle(Update $update): void
    {
        $this->panel->show($update, $this->reportText());
    }

    /**
     * Every method, switched on or off in place.
     *
     * Methods that have taken money but are no longer registered appear
     * too, marked as gone. Hiding them would make an admin's revenue
     * disappear at exactly the moment they are trying to work out where
     * it went.
     */
    public function methods(Update $update, string $notice = ''): void
    {
        $report = $this->finance->report(self::WINDOW);
        $lines = ['<b>Payment methods</b>', ''];
        $keyboard = Keyboard::inline();

        if ($report['methods'] === []) {
            $lines[] = 'No payment methods are installed.';
            $lines[] = '';
            $lines[] = '<i>A payment method arrives with an extension. '
                . 'Install one, then switch it on here.</i>';
            $lines[] = '';
        }

        foreach ($report['methods'] as $method) {
            $state = match (true) {
                !$method['registered'] => '⚪️ gone',
                !$method['configured'] => '🟡 not configured',
                $method['enabled'] => '🟢 on',
                default => '🔴 off',
            };

            $lines[] = sprintf(
                '%s <b>%s</b> - %s over %d days',
                $state,
                $this->escape((string) $method['title']),
                $this->escape($this->wallet->money((int) $method['amount'])->format()),
                self::WINDOW
            );

            // What the method says about itself, which is where a method
            // that is switched on and still not taking money gets to
            // explain why -- an admin should not have to go and read the
            // extension's settings to find out.
            if (($method['description'] ?? '') !== '') {
                $lines[] = '<i>' . $this->escape((string) $method['description']) . '</i>';
            }

            $lines[] = '';

            if (!$method['registered']) {
                continue;
            }

            $keyboard->row(InlineButton::callback(
                ($method['enabled'] ? '🔴 Turn off: ' : '🟢 Turn on: ') . $method['title'],
                ToggleMethod::to((string) $method['key'])
            ));
        }

        $lines[] = '<i>Off means customers are not offered it. '
            . 'Nothing already taken is affected.</i>';

        $this->panel->show(
            $update,
            $notice === '' ? implode(PHP_EOL, $lines) : $notice . PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines),
            $keyboard->build()
        );
    }

    /**
     * The report, small enough to read on a phone.
     *
     * The web page is where the same figures get charts and a longer
     * window; this is the version an operator checks between messages.
     */
    private function reportText(): string
    {
        $report = $this->finance->report(self::WINDOW);

        $lines = [
            '<b>Top-ups</b>',
            '',
            $this->window('Today', $report['windows']['today']),
            $this->window('7 days', $report['windows']['week']),
            $this->window(self::WINDOW . ' days', $report['windows']['window']),
            '',
            'Average: ' . $this->money((int) $report['average']),
            'Largest: ' . $this->money((int) $report['largest']),
            '',
            'Held by customers: <b>' . $this->money((int) $report['held']) . '</b>'
                . ' across ' . (int) $report['wallets'] . ' wallets',
        ];

        $earning = array_filter($report['methods'], fn (array $m) => $m['count'] > 0);

        if ($earning !== []) {
            $lines[] = '';
            $lines[] = '<b>By method</b>';

            foreach ($earning as $method) {
                $lines[] = sprintf(
                    '%s - %s (%d)',
                    $this->escape((string) $method['title']),
                    $this->money((int) $method['amount']),
                    (int) $method['count']
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /** @param array{count:int, amount:int, customers:int} $window */
    private function window(string $label, array $window): string
    {
        return sprintf(
            '%s: <b>%s</b> - %d top-ups, %d customers',
            $label,
            $this->money($window['amount']),
            $window['count'],
            $window['customers']
        );
    }

    private function money(int $minor): string
    {
        return $this->escape($this->wallet->money($minor)->format());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
