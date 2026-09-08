<?php

return [

    'bot_token' => $_ENV['BOT_TOKEN'] ?? 'YOUR_BOT_TOKEN',
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'name' => $_ENV['DB_NAME'] ?? 'YOUR_DB_NAME',
        'user' => $_ENV['DB_USER'] ?? 'YOUR_DB_USER',
        'password' => $_ENV['DB_PASSWORD'] ?? 'YOUR_DB_PASSWORD',
    ],

    'paths' => [
        'root' => dirname(__DIR__),
        'extensions' => dirname(__DIR__) . '/extensions',
        'storage' => dirname(__DIR__) . '/storage',

        // Extension setting overrides. Deliberately outside the
        // extensions folder so an update cannot wipe them.
        'settings' => dirname(__DIR__) . '/storage/extension-settings',
    ],

    // The remote archive extensions and core updates are fetched from.
    //
    // Set by hand, here, rather than through .env: an archive you let ship
    // executable code into your install is a deployment decision, and it
    // belongs in a file that gets read during review rather than in an
    // environment variable that can change without one.
    //
    // Everything in this block is safe to leave alone if you do not use an
    // archive; an empty url simply disables every remote command.
    'archive' => [

        // Base URL of your hub, no trailing slash. HTTPS is required
        // unless the host is localhost, since this channel delivers code.
        // the offical hub is at https://botex.imamin.me, but you can run your own using https://github.com/ajfmin/botexHub.

        'url' => 'https://botex.imamin.me',

        // Which release line to follow. The hub decides what these mean;
        // 'stable' is the convention.
        'channel' => 'stable',

        // Leave true. Turning off certificate verification means anyone on
        // the network path can serve you a package of their choosing.
        'verify_tls' => true,

        // Seconds to wait on a connection. Downloads get twice this.
        'timeout' => 20,

        // Paste an RSA public key (the ----BEGIN PUBLIC KEY---- block, or
        // a path to it) to require that every package is signed by the
        // matching private key. Empty means unsigned packages are accepted
        // on TLS and checksums alone -- fine for an archive only you can
        // publish to, not enough for a shared one.
        //
        // Generate a pair with: php hub/bin/hub keygen
        'public_key' => '',

        // Seconds an archive answer is reused for. The admin panel reads
        // the catalog from this cache, so a webhook never waits on the
        // network. 0 disables caching.
        'cache_ttl' => 3600,
    ],

    // Token for the extensions web panel. Empty disables the panel.
    'panel_token' => $_ENV['PANEL_TOKEN'] ?? '',

    'wallet' => [
        // Label only; balances are always integer minor units.
        'currency' => $_ENV['WALLET_CURRENCY'] ?? 'IRT',

        // Minor units per whole unit, as a power of ten. 0 for Toman,
        // which has no subunit in practice; 2 for USD cents.
        'scale' => (int) ($_ENV['WALLET_SCALE'] ?? 0),
    ],

    'actions' => [
        // How long a rendered Run button stays pressable, in seconds.
        // A button lives in an old message forever, so actions expire by
        // default; override per action with Run::expiresIn/never.
        'ttl' => (int) ($_ENV['ACTION_TTL'] ?? 604800),

        // Prune expired rows roughly once every N updates. Cheap enough
        // to ride along on webhook traffic instead of needing cron.
        'prune_chance' => (int) ($_ENV['ACTION_PRUNE_CHANCE'] ?? 200),
    ],

    'jobs' => [
        // Seconds the worker waits before polling again when it found
        // nothing due. Lower feels more immediate and costs more queries.
        'sleep' => (int) ($_ENV['JOB_SLEEP'] ?? 5),

        // How long a claimed job stays owned. Must comfortably exceed your
        // slowest job, or a still-running job looks abandoned and gets
        // picked up again; long jobs can push this out with
        // JobContext::heartbeat().
        'lease' => (int) ($_ENV['JOB_LEASE'] ?? 300),

        // Attempts a failing job gets before it is marked failed.
        'max_attempts' => (int) ($_ENV['JOB_MAX_ATTEMPTS'] ?? 3),

        // Finished rows are kept this long, then removed by the core
        // prune job. 0 disables scheduling that job at all.
        'keep_finished' => (int) ($_ENV['JOB_KEEP_FINISHED'] ?? 604800),
    ],

    'logging' => [
        // Quietest level written to storage/logs/app-YYYY-MM-DD.log.
        // 'debug' shows every dispatch; 'info' records milestones;
        // 'warning' and above keeps only what looks like trouble.
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',

        // Days of log files kept; 0 keeps them forever. Old files are
        // deleted lazily, whenever a critical entry is written.
        'keep_days' => (int) ($_ENV['LOG_KEEP_DAYS'] ?? 14),

        // Telegram chat the admins are notified in: a group id (negative,
        // invite the bot first) or a user id. Empty disables notifying.
        'notify_chat' => trim($_ENV['LOG_CHAT'] ?? ''),

        // Quietest level that reaches that chat. The file and the chat have
        // separate thresholds so routine detail never pages anybody.
        'notify_level' => $_ENV['LOG_NOTIFY_LEVEL'] ?? 'error',
    ],

    // Comma separated telegram ids, e.g. ADMIN_IDS=12345,67890
    'admins' => array_values(array_filter(array_map(
        'intval',
        array_filter(explode(',', $_ENV['ADMIN_IDS'] ?? ''), 'strlen')
    ))),

];
