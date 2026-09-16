<?php

namespace Botex\Bot\Admin\Section;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\HasSubMenu;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Callback\Admin\ToggleMethod;
use Botex\Bot\TopUp\Finance;
use Botex\Bot\TopUp\MethodState;
use Botex\Bot\TopUp\PaymentMethods;
use Botex\Service\WalletService;
use Botex\Support\Log\Log;
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
 *
 * **The switches do not depend on the ledger.** [[MethodState]] is a
 * file rather than a table precisely so a payment route can be turned
 * off on a bot whose database is the thing that is broken -- and that
 * promise is worth nothing if the screen carrying the switches refuses
 * to draw without a successful query. So the list is built from the
 * registry and the state file, and the figures from [[Finance]] are
 * decoration: when the report cannot be read the switches still render,
 * with a line saying so. Being unable to see this month's total is an
 * inconvenience; being unable to close a payment route while something
 * is going wrong is an incident.
 */
class TopUp implements AdminSectionInterface, HasSubMenu
{
    /** Days the Telegram report covers. Short: it is read on a phone. */
    private const WINDOW = 30;

    public function __construct(
        private Panel $panel,
        private Finance $finance,
        private PaymentMethods $methods,
        private MethodState $state,
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
     * The report, or null if it could not be read.
     *
     * Every figure on both of these screens comes from one query against
     * `topups`, and a bot updated without `php bin/console migrate` has
     * no such table. That used to take the whole screen down: the
     * handler threw, the webhook answered 400, and Telegram showed the
     * admin nothing at all -- so the most visible symptom of a missing
     * migration was a switch that appeared to do nothing when pressed.
     *
     * Logged rather than swallowed. This is a real fault and somebody
     * should fix it; it just should not be able to take the switches
     * with it.
     *
     * @return array<string, mixed>|null
     */
    private function tryReport(): ?array
    {
        try {
            return $this->finance->report(self::WINDOW);
        } catch (\Throwable $e) {
            Log::exception($e, 'Top-up report could not be read', context: ['section' => self::key()]);

            return null;
        }
    }

    /**
     * The methods, from the registry and the switch file alone.
     *
     * The same shape [[Finance]] returns, with every figure null instead
     * of zero: a method that has taken nothing and a method whose
     * takings cannot be read are different facts, and printing "0" for
     * the second is a lie an admin would act on.
     *
     * Methods that are no longer registered cannot appear here, because
     * knowing they existed requires reading the ledger. That is the one
     * thing lost when the report is down, and it is the right thing to
     * lose: this screen's job in that state is to let somebody operate
     * the switches that do exist.
     *
     * @return array<string, array<string, mixed>>
     */
    private function switchesOnly(): array
    {
        $methods = [];

        foreach ($this->methods->all() as $key => $_) {
            $instance = $this->methods->make($key);

            $methods[$key] = [
                'key' => $key,
                'title' => $instance === null ? $key : $instance::title(),
                'description' => $instance === null ? '' : $instance->description(),
                'count' => null,
                'amount' => null,
                'enabled' => $this->state->isEnabled($key),
                'registered' => true,
                'configured' => $instance !== null && $instance->isConfigured(),
            ];
        }

        return $methods;
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
        $report = $this->tryReport();
        $lines = ['<b>Payment methods</b>', ''];
        $keyboard = Keyboard::inline();

        if ($report === null) {
            $lines[] = '⚠️ <i>The figures below could not be read; the switches still work. '
                . 'If this bot was just updated, run <code>php bin/console migrate</code>.</i>';
            $lines[] = '';
        }

        $report ??= ['methods' => $this->switchesOnly()];

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

            $lines[] = $method['amount'] === null
                ? $state . ' <b>' . $this->escape((string) $method['title']) . '</b>'
                : sprintf(
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
        $report = $this->tryReport();

        if ($report === null) {
            // A report that cannot be read is worth saying out loud, and
            // worth saying the likely reason for: on an install updated
            // in place this is almost always the migration that has not
            // been run yet.
            return '<b>Top-ups</b>' . PHP_EOL . PHP_EOL
                . '⚠️ The report could not be read.' . PHP_EOL . PHP_EOL
                . 'If this bot was updated recently, run '
                . '<code>php bin/console migrate</code> and try again. '
                . 'The payment switches on the next screen still work.';
        }

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
