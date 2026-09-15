<?php

/**
 * Read-only financial report: what came in, by day and by method.
 *
 * SECURITY: reachable by anyone who can reach the web root, so it is
 * gated behind PANEL_TOKEN and fails closed when that is unset. Like
 * panel.php it is deliberately read-only -- switching a payment method
 * on or off stays in the admin panel and the CLI, so a leaked token
 * cannot open a payment route.
 *
 * Nothing here identifies a customer. Top-ups are counted and summed,
 * and the only per-row identifier is the internal user id, which means
 * nothing outside this database. Telegram ids, names and balances stay
 * out, exactly as they do on panel.php.
 *
 *   /finance.php?token=<PANEL_TOKEN>&days=30
 */

use Botex\Bot\TopUp\Finance;
use Botex\Repository\TopUpRepository;
use Botex\Service\WalletService;
use Botex\Support\Config;
use Goat1000\SVGGraph\SVGGraph;

/** @var Botex\Bot\Feeder $feeder */
$feeder = require __DIR__ . '/../bootstrap/app.php';

$expected = (string) $feeder->get(Config::class)->get('panel_token', '');
$provided = (string) ($_GET['token'] ?? '');

if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(404);
    exit('Not found');
}

/** Windows offered, in days. Bounded: the report reads every row in one. */
const WINDOWS = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'];

$days = (int) ($_GET['days'] ?? 30);
$days = array_key_exists($days, WINDOWS) ? $days : 30;

$errors = [];
$report = null;
$recent = [];
$money = static fn (int $minor): string => (string) $minor;

try {
    $wallet = $feeder->make(WalletService::class);
    $money = static fn (int $minor): string => $wallet->money($minor)->format();

    $report = $feeder->make(Finance::class)->report($days);
    $recent = $feeder->make(TopUpRepository::class)->latest(25);
} catch (\Throwable $e) {
    // An unmigrated database must not render a blank page with no clue
    // as to why; the token check has already passed, so the reader is an
    // operator who can act on the message.
    $errors['finance'] = $e->getMessage();
}

/**
 * Escapes one value for this page.
 *
 * Guarded and distinctly named on purpose. A bare `e()` here is the
 * same name illuminate/support puts in the global namespace, and which
 * of the two wins depends on whether this file was compiled before or
 * after the autoloader ran -- fine when the page is requested directly,
 * a fatal the moment anything includes it after booting. Escaping is
 * not a thing to leave depending on load order.
 */
if (!function_exists('esc')) {
    function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * One chart, as inline SVG.
 *
 * Inline rather than an <img> or a canvas: there is no asset pipeline
 * here and no network to fetch a chart library from, and an operator
 * reading this on a server with no outbound access should still see the
 * shape of their month.
 *
 * The prefix is a namespace for the element ids, not a per-chart one --
 * SVGGraph numbers elements from a counter shared by every graph in the
 * process, so three charts on one page already cannot collide. The
 * prefix only keeps them from colliding with the page's own markup.
 *
 * @param array<string, int|float> $values
 * @param array<string, mixed>     $settings
 */
function chart(string $type, array $values, array $settings = [], string $colour = '#2f6fed'): string
{
    if ($values === []) {
        return '<p class="empty">Nothing to chart yet.</p>';
    }

    try {
        $graph = new SVGGraph(760, 260, array_merge([
            'auto_fit' => false,
            'back_colour' => 'none',
            'stroke_colour' => 'none',
            'show_grid_v' => false,
            'grid_colour' => '#ececec',
            'axis_colour' => '#d8d8d8',
            'axis_text_colour' => '#666',
            'label_font_size' => 10,
            'pad_left' => 76,
            'pad_bottom' => 48,
            'pad_top' => 10,
            'pad_right' => 10,
            'bar_space' => 4,
            'id_prefix' => 'chart_',
        ], $settings));

        $graph->colours([[$colour]]);
        $graph->values($values);

        // false, false: no XML header (this is going inside a page) and
        // no deferred javascript (there is no loader on this page to run
        // it later, so the tooltip script is inlined with its own chart).
        return $graph->fetch($type, false, false);
    } catch (\Throwable $e) {
        return '<p class="err">' . esc($e->getMessage()) . '</p>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finance</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 55rem; padding: 0 1rem; color: #1c1c1c; }
        h1 { font-size: 1.25rem; }
        h2 { font-size: 1rem; margin-top: 2rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: .6rem .5rem; border-bottom: 1px solid #e3e3e3; }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .status { font-size: .8125rem; padding: .15rem .5rem; border-radius: 1rem; }
        .on { background: #e6f4ea; color: #1e6b34; }
        .off { background: #fdecea; color: #8c1d18; }
        .unset { background: #fef3c7; color: #7c5000; }
        .gone { background: #eee; color: #555; }
        .error { background: #fdecea; color: #8c1d18; padding: .75rem; border-radius: .25rem; margin-bottom: 1rem; font-size: .875rem; }
        .err { color: #8c1d18; font-size: .8125rem; }
        .empty { color: #666; }
        .desc { color: #666; font-size: .8125rem; margin-top: .25rem; max-width: 34rem; }
        .cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .card { border: 1px solid #e3e3e3; border-radius: .375rem; padding: .75rem 1rem; min-width: 10rem; }
        .card dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .card dd { margin: .25rem 0 0; font-size: 1.125rem; font-weight: 600; }
        .card dd small { font-weight: 400; color: #666; font-size: .8125rem; }
        .windows { margin: 0 0 1.5rem; padding: 0; list-style: none; display: flex; gap: .5rem; flex-wrap: wrap; }
        .windows a { display: inline-block; padding: .25rem .75rem; border: 1px solid #e3e3e3; border-radius: 1rem; text-decoration: none; color: #1c1c1c; font-size: .875rem; }
        .windows a.current { background: #1c1c1c; color: #fff; border-color: #1c1c1c; }
        .chart { border: 1px solid #e3e3e3; border-radius: .375rem; padding: .5rem; overflow-x: auto; }
        .nav { font-size: .875rem; margin-bottom: 1.5rem; }
        code { background: #f4f4f4; padding: .1rem .3rem; border-radius: .2rem; }
    </style>
</head>
<body>
    <p class="nav"><a href="panel.php?token=<?= esc($provided) ?>">&larr; Extensions</a></p>

    <h1>Finance</h1>

    <?php foreach ($errors as $where => $message): ?>
        <p class="error"><strong><?= esc($where) ?>:</strong> <?= esc($message) ?></p>
    <?php endforeach; ?>

<?php if ($report !== null): ?>

    <ul class="windows">
        <?php foreach (WINDOWS as $value => $label): ?>
            <li>
                <a class="<?= $value === $days ? 'current' : '' ?>"
                   href="?token=<?= esc($provided) ?>&amp;days=<?= (int) $value ?>"><?= esc($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="cards">
        <dl class="card">
            <dt>Today</dt>
            <dd><?= esc($money((int) $report['windows']['today']['amount'])) ?>
                <small><?= (int) $report['windows']['today']['count'] ?> top-ups</small></dd>
        </dl>
        <dl class="card">
            <dt>7 days</dt>
            <dd><?= esc($money((int) $report['windows']['week']['amount'])) ?>
                <small><?= (int) $report['windows']['week']['count'] ?> top-ups</small></dd>
        </dl>
        <dl class="card">
            <dt><?= esc(WINDOWS[$days]) ?></dt>
            <dd><?= esc($money((int) $report['windows']['window']['amount'])) ?>
                <small><?= (int) $report['windows']['window']['count'] ?> top-ups</small></dd>
        </dl>
        <dl class="card">
            <dt>Customers</dt>
            <dd><?= (int) $report['windows']['window']['customers'] ?>
                <small>in <?= esc(WINDOWS[$days]) ?></small></dd>
        </dl>
    </div>

    <div class="cards">
        <dl class="card">
            <dt>Average top-up</dt>
            <dd><?= esc($money((int) $report['average'])) ?></dd>
        </dl>
        <dl class="card">
            <dt>Largest</dt>
            <dd><?= esc($money((int) $report['largest'])) ?></dd>
        </dl>
        <dl class="card">
            <dt>Held by customers</dt>
            <dd><?= esc($money((int) $report['held'])) ?>
                <small><?= (int) $report['wallets'] ?> wallets</small></dd>
        </dl>
    </div>

    <h2>Daily intake</h2>
    <div class="chart">
        <?php
        // Amounts are minor units, which is what the axis should show:
        // rescaling for display would make the tooltip disagree with
        // every other figure on the page.
        $daily = [];

        foreach ($report['daily'] as $row) {
            $daily[substr((string) $row['day'], 5)] = (int) $row['amount'];
        }

        // A long window has one label per day, which is unreadable at
        // this width; SVGGraph thins them out when asked.
        echo chart('BarGraph', $daily, [
            'label_v' => 'Amount (' . $report['currency'] . ')',
            'axis_text_angle_h' => count($daily) > 14 ? -60 : 0,
            'grid_division_h' => count($daily) > 60 ? ceil(count($daily) / 30) : 1,
        ]);
        ?>
    </div>

    <h2>Top-ups per day</h2>
    <div class="chart">
        <?php
        $counts = [];

        foreach ($report['daily'] as $row) {
            $counts[substr((string) $row['day'], 5)] = (int) $row['count'];
        }

        echo chart('LineGraph', $counts, [
            'label_v' => 'Top-ups',
            'axis_text_angle_h' => count($counts) > 14 ? -60 : 0,
            'marker_size' => 3,
            'line_stroke_width' => 2,
            'grid_division_h' => count($counts) > 60 ? ceil(count($counts) / 30) : 1,
        ], '#1e6b34');
        ?>
    </div>

    <h2>By method</h2>
    <?php
    $earning = array_filter($report['methods'], static fn (array $m) => $m['amount'] > 0);
    ?>
    <?php if ($earning !== []): ?>
        <div class="chart">
            <?php
            $byMethod = [];

            foreach ($earning as $method) {
                $byMethod[(string) $method['title']] = (int) $method['amount'];
            }

            echo chart('HorizontalBarGraph', $byMethod, [
                'pad_left' => 160,
                'label_h' => 'Amount (' . $report['currency'] . ')',
            ], '#7c5000');
            ?>
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th>State</th>
                <th class="num">Top-ups</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($report['methods'] === []): ?>
                <tr><td colspan="4" class="empty">No payment methods installed.</td></tr>
            <?php endif; ?>
            <?php foreach ($report['methods'] as $method): ?>
                <tr>
                    <td>
                        <?= esc((string) $method['title']) ?><br>
                        <code><?= esc((string) $method['key']) ?></code>
                        <?php if (($method['description'] ?? '') !== ''): ?>
                            <div class="desc"><?= esc((string) $method['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        // Four states, not two: an admin needs to tell "I
                        // turned this off" from "this cannot work yet"
                        // from "the extension that owned it is gone".
                        [$class, $label] = match (true) {
                            !$method['registered'] => ['gone', 'not installed'],
                            !$method['configured'] => ['unset', 'not configured'],
                            $method['enabled'] => ['on', 'on'],
                            default => ['off', 'off'],
                        };
                        ?>
                        <span class="status <?= $class ?>"><?= esc($label) ?></span>
                    </td>
                    <td class="num"><?= (int) $method['count'] ?></td>
                    <td class="num"><?= esc($money((int) $method['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Latest top-ups</h2>
    <table>
        <thead>
            <tr>
                <th>When</th>
                <th>Method</th>
                <th>User</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($recent === []): ?>
                <tr><td colspan="4" class="empty">No top-ups recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= esc($row->created_at ? $row->created_at->format('Y-m-d H:i') : '') ?></td>
                    <td><code><?= esc((string) $row->method) ?></code></td>
                    <td>#<?= (int) $row->user_id ?></td>
                    <td class="num"><?= esc($money((int) $row->amount)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="empty" style="margin-top:2rem;font-size:.8125rem">
        Window starts <?= esc((string) $report['from']) ?>.
        Amounts are wallet minor units in <?= esc((string) $report['currency']) ?>.
    </p>

<?php endif; ?>
</body>
</html>
