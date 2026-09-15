<?php

/**
 * Read-only list of installed extensions.
 *
 * SECURITY: this is reachable by anyone who can reach the web root, so
 * it is gated behind PANEL_TOKEN and fails closed when that is unset.
 * It is deliberately read-only: enable/disable/remove stay in the CLI
 * so a leaked token cannot delete files.
 *
 *   /panel.php?token=<PANEL_TOKEN>
 */

use Botex\Extension\Registry;
use Botex\Service\WalletService;
use Botex\Support\Config;

/** @var Botex\Bot\Feeder $feeder */
$feeder = require __DIR__ . '/../bootstrap/app.php';

$expected = (string) $feeder->get(Config::class)->get('panel_token', '');
$provided = (string) ($_GET['token'] ?? '');

if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(404);
    exit('Not found');
}

$registry = $feeder->get(Registry::class);
$extensions = $registry->all();
$errors = $registry->errors();

// Totals only. Per-user balances stay out of a token-gated page.
$wallet = null;

try {
    $wallet = $feeder->make(WalletService::class)->stats();
} catch (\Throwable $e) {
    // Not migrated yet, which should not take the extension list down.
    $errors['wallet'] = $e->getMessage();
}

// Counts and action names only: no tokens, no action data.
$actions = null;
$runnables = [];

try {
    $store = $feeder->make(\Botex\Bot\Action\ActionStore::class);

    $actions = [
        'stored' => $store->count(),
        'live' => $store->countLive(),
    ];

    $runnables = $feeder->make(\Botex\Bot\Action\Runnables::class)->all();

    // A button may also target a command, so the same page lists both.
    foreach ($feeder->make(Botex\Bot\Command\Commands::class)->map() as $verb => $class) {
        $runnables['command:/' . $verb] = $class;
    }
} catch (\Throwable $e) {
    $errors['actions'] = $e->getMessage();
}

// Scheduled work, plus whether the one worker service is actually up.
// Names, timings and counts only: job data can hold anything a caller put
// there, so it is not rendered here.
$jobStats = null;
$jobRows = [];
$workerState = 'unknown';

try {
    $jobService = $feeder->make(Botex\Service\JobService::class);

    $jobStats = $jobService->stats();
    $jobRows = $jobService->recent(30);
    $workerState = $feeder->make(Botex\Bot\Job\WorkerLease::class)->describe();
} catch (\Throwable $e) {
    $errors['jobs'] = $e->getMessage();
}

// Available updates, read from the cache the CLI wrote. No network call:
// a page load must not hang because a third-party archive is slow, and
// nothing here writes a file -- the commands are printed to run on the host.
$updates = null;

try {
    $availability = $feeder->make(Botex\Update\Availability::class);

    $updates = [
        'configured' => $availability->configured(),
        'known' => $availability->known(),
        'core' => $availability->core(),
        'extensions' => $availability->extensions(),
        'baselined' => $availability->baselined(),
        'conflicts' => $availability->conflicts(),
    ];
} catch (\Throwable $e) {
    $errors['updates'] = $e->getMessage();
}

/**
 * Escapes one value for this page.
 *
 * Guarded, and no longer called `e()`. That is the same name
 * illuminate/support puts in the global namespace, and which of the two
 * won depended on whether this file was compiled before or after the
 * autoloader ran -- fine for a direct request, a fatal the moment
 * anything includes the page after booting. Escaping is not a thing to
 * leave depending on load order.
 */
if (!function_exists('esc')) {
    function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extensions</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 55rem; padding: 0 1rem; color: #1c1c1c; }
        h1 { font-size: 1.25rem; }
        table { border-collapse: collapse; width: 100%; }
        caption { text-align: left; padding-bottom: .5rem; color: #555; font-size: .875rem; }
        th, td { text-align: left; padding: .6rem .5rem; border-bottom: 1px solid #e3e3e3; }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .status { font-size: .8125rem; padding: .15rem .5rem; border-radius: 1rem; }
        .enabled { background: #e6f4ea; color: #1e6b34; }
        .disabled { background: #eee; color: #555; }
        .desc { color: #555; font-size: .875rem; }
        .error { background: #fdecea; color: #8c1d18; padding: .75rem; border-radius: .25rem; margin-bottom: 1rem; font-size: .875rem; }
        .empty { color: #666; }
        code { background: #f4f4f4; padding: .1rem .3rem; border-radius: .2rem; }
        .cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; }
        .card { border: 1px solid #e3e3e3; border-radius: .375rem; padding: .75rem 1rem; min-width: 11rem; }
        .card dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .card dd { margin: .25rem 0 0; font-size: 1.125rem; font-weight: 600; }
        .worker { font-size: .9375rem; }
        .worker.up { color: #1e6b34; }
        .worker.down { color: #8c1d18; }
        .job-pending { background: #e8f0fe; color: #1a3f8f; }
        .job-running { background: #fef3c7; color: #7c5000; }
        .job-done { background: #e6f4ea; color: #1e6b34; }
        .job-failed { background: #fdecea; color: #8c1d18; }
        .job-paused { background: #eee; color: #555; }
        .err { color: #8c1d18; font-size: .8125rem; }
        .nav { font-size: .875rem; margin: -.5rem 0 1.5rem; }
    </style>
</head>
<body>
    <h1>Overview</h1>

    <p class="nav"><a href="finance.php?token=<?= esc($provided) ?>">Financial report &rarr;</a></p>

    <?php if ($wallet !== null): ?>
        <div class="cards">
            <dl class="card">
                <dt>Wallets opened</dt>
                <dd><?= esc((string) $wallet['wallets']) ?></dd>
            </dl>
            <dl class="card">
                <dt>Total held</dt>
                <dd><?= esc($wallet['formatted']) ?></dd>
            </dl>
        </div>
    <?php endif; ?>

    <?php if ($actions !== null): ?>
        <div class="cards">
            <dl class="card">
                <dt>Stored actions</dt>
                <dd><?= esc((string) $actions['stored']) ?></dd>
            </dl>
            <dl class="card">
                <dt>Still pressable</dt>
                <dd><?= esc((string) $actions['live']) ?></dd>
            </dl>
            <dl class="card">
                <dt>Registered actions</dt>
                <dd><?= esc((string) count($runnables)) ?></dd>
            </dl>
        </div>
    <?php endif; ?>

    <?php if ($jobStats !== null): ?>
        <div class="cards">
            <dl class="card">
                <dt>Worker</dt>
                <dd class="worker <?= str_starts_with($workerState, 'running') ? 'up' : 'down' ?>">
                    <?= esc($workerState) ?>
                </dd>
            </dl>
            <dl class="card">
                <dt>Jobs scheduled</dt>
                <dd><?= esc((string) $jobStats['pending']) ?></dd>
            </dl>
            <dl class="card">
                <dt>Due now</dt>
                <dd><?= esc((string) $jobStats['due']) ?></dd>
            </dl>
            <dl class="card">
                <dt>Failed</dt>
                <dd><?= esc((string) $jobStats['failed']) ?></dd>
            </dl>
        </div>
    <?php endif; ?>

    <?php if ($updates !== null): ?>
        <h1>Updates</h1>

        <?php if (!$updates['configured']): ?>
            <p class="empty">
                No archive configured. Set <code>archive.url</code> in
                <code>config/config.php</code> to check for updates.
            </p>
        <?php elseif (!$updates['known']): ?>
            <p class="empty">
                Not checked yet. Run <code>php bin/console core:check</code> on the host.
            </p>
        <?php else: ?>
            <?php if (!$updates['baselined']): ?>
                <p class="error">
                    No baseline recorded, so local edits to core files cannot be
                    detected. Run <code>php bin/console core:adopt</code>.
                </p>
            <?php elseif ($updates['conflicts'] !== []): ?>
                <p class="error">
                    <?= esc((string) count($updates['conflicts'])) ?> core file(s) edited
                    locally. A core update will stop rather than overwrite them &mdash;
                    see <code>php bin/console core:diff</code>.
                </p>
            <?php endif; ?>

            <?php if ($updates['core'] === null && $updates['extensions'] === []): ?>
                <p class="empty">Everything is up to date.</p>
            <?php else: ?>
                <table>
                    <caption>
                        From the last check, so it may be a little behind. Applying an
                        update is a CLI action: this page never downloads or writes code.
                    </caption>
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>Installed</th>
                            <th>Available</th>
                            <th>Apply on the host</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($updates['core'] !== null): ?>
                            <tr>
                                <td><strong>Botex core</strong></td>
                                <td><?= esc(Botex\Botex::VERSION) ?></td>
                                <td><?= esc($updates['core']->version) ?></td>
                                <td><code>php bin/console core:update</code></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($updates['extensions'] as $row): ?>
                            <tr>
                                <td>
                                    <?= esc($row['name']) ?>
                                    <div class="desc"><?= esc($row['slug']) ?></div>
                                </td>
                                <td><?= esc($row['installed']) ?></td>
                                <td><?= esc($row['available']) ?></td>
                                <td><code>php bin/console ext:update <?= esc($row['slug']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h1>Extensions</h1>

    <?php foreach ($errors as $slug => $message): ?>
        <p class="error"><strong><?= esc((string) $slug) ?></strong>: <?= esc($message) ?></p>
    <?php endforeach; ?>

    <?php if (!$extensions): ?>
        <p class="empty">No extensions installed.</p>
    <?php else: ?>
        <table>
            <caption>Manage with <code>php bin/console ext:list</code></caption>
            <thead>
                <tr>
                    <th scope="col">Slug</th>
                    <th scope="col">Name</th>
                    <th scope="col">Version</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($extensions as $manifest): ?>
                    <?php $on = $registry->isEnabled($manifest->slug); ?>
                    <tr>
                        <td><code><?= esc($manifest->slug) ?></code></td>
                        <td>
                            <?= esc($manifest->name) ?>
                            <?php if ($manifest->description !== ''): ?>
                                <div class="desc"><?= esc($manifest->description) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($manifest->version) ?></td>
                        <td>
                            <span class="status <?= $on ? 'enabled' : 'disabled' ?>">
                                <?= $on ? 'enabled' : 'disabled' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($jobStats !== null): ?>
        <h1>Jobs</h1>

        <?php if (!str_starts_with($workerState, 'running')): ?>
            <p class="error">
                No worker is running, so nothing scheduled will happen.
                Start one with <code>php bin/console jobs:work</code>.
            </p>
        <?php endif; ?>

        <?php if (count($jobRows) === 0): ?>
            <p class="empty">Nothing scheduled.</p>
        <?php else: ?>
            <table>
                <caption>Manage with <code>php bin/console jobs:list</code></caption>
                <thead>
                    <tr>
                        <th scope="col">Job</th>
                        <th scope="col">Status</th>
                        <th scope="col">Schedule</th>
                        <th scope="col">Next run</th>
                        <th scope="col">Runs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobRows as $row): ?>
                        <tr>
                            <td>
                                <code><?= esc((string) $row->job) ?></code>
                                <div class="desc">
                                    <?= esc((string) $row->extension) ?>
                                    &middot; #<?= esc((string) (int) $row->id) ?>
                                </div>
                            </td>
                            <td>
                                <span class="status job-<?= esc($row->status()->value) ?>">
                                    <?= esc($row->status()->label()) ?>
                                </span>
                            </td>
                            <td><?= esc($row->describeSchedule()) ?></td>
                            <td><?= esc($row->describeNextRun()) ?></td>
                            <td>
                                <?= esc((string) (int) $row->runs) ?>
                                <?php if ((int) $row->failures > 0): ?>
                                    <div class="err"><?= esc((string) (int) $row->failures) ?> failed</div>
                                <?php endif; ?>
                                <?php if ($row->last_error): ?>
                                    <div class="err"><?= esc((string) $row->last_error) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($runnables): ?>
        <h1>Run actions</h1>
        <table>
            <caption>What a Run button may be bound to</caption>
            <thead>
                <tr>
                    <th scope="col">Action</th>
                    <th scope="col">Extension</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($runnables as $key => $class): ?>
                    <?php [$owner, $name] = explode(':', (string) $key, 2); ?>
                    <tr>
                        <td><code><?= esc($name) ?></code></td>
                        <td><?= $owner === 'command' ? 'command' : esc($owner) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
