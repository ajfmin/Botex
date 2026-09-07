<?php

/**
 * Shared boot sequence for every entry point (webhook, console, panel).
 * Returns a Feeder with Config registered and Eloquent booted.
 */

require __DIR__ . '/../vendor/autoload.php';

use Botex\Bot\CurrentUpdate;
use Botex\Bot\Feeder;
use Botex\Compat;
use Botex\Extension\Registry;
use Botex\Extension\SettingsFactory;
use Botex\Extension\State;
use Botex\Support\Config;
use Botex\Support\Log\Level;
use Botex\Support\Log\Log;
use Botex\Support\Log\Logger;
use Botex\Support\Log\LogNotifier;
use Botex\Telegram\Bot;
use Illuminate\Database\Capsule\Manager as Capsule;

// Registered before anything can autoload an extension: Botex used to ship
// its core under App\, and an extension written against that namespace has
// to keep loading. Costs one closure on the autoload stack when nothing
// needs it. See Botex\Compat.
Compat::register();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$config = require __DIR__ . '/../config/config.php';

$capsule = new Capsule;

$capsule->addConnection([
    'driver' => 'mysql',
    'host' => $config['database']['host'],
    'database' => $config['database']['name'],
    'username' => $config['database']['user'],
    'password' => $config['database']['password'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'options' => [
        // Without this, MySQL reports rows *changed* rather than rows
        // *matched*, so an UPDATE that sets a column to the value it
        // already holds returns 0. Everything that proves ownership in
        // this app reads its answer from that count -- claiming a job,
        // renewing a lease, debiting a wallet -- so the two must not be
        // confusable. With FOUND_ROWS, 0 means "the WHERE matched
        // nothing", which is the only thing those guards ever meant.
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
    ],
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

$feeder = new Feeder();
$feeder->set(Config::class, new Config($config));

// so anything resolving Feeder gets this same container
$feeder->set(Feeder::class, $feeder);

// Reachable statically as well, for the one case that cannot be injected:
// a Keyboard is built with a static constructor, so a button carrying a Run
// action has no constructor to receive the binder through.
$feeder->share();

// Registry and State take string paths, which reflection cannot
// autowire, so they are built here and registered.
$state = new State($config['paths']['storage'] . '/extensions.json');
$feeder->set(State::class, $state);

$registry = new Registry($config['paths']['extensions'], $state);
$feeder->set(Registry::class, $registry);

// Also a string path, so it cannot be autowired either.
$feeder->set(
    SettingsFactory::class,
    new SettingsFactory($registry, $config['paths']['settings'])
);

// Takes a token string, so it cannot be autowired. Registered here rather
// than per entry point because a job sending a message has no update to
// build one from, and the worker runs with no request at all.
$feeder->set(Bot::class, new Bot($config['bot_token']));

// The logger takes string paths and level names, and the notifier needs
// the Bot above it, so this is built here too. It registers itself with
// the Log facade as well: boot errors happen before anything can inject
// it, and silence at exactly that moment is the worst outcome.
$logLevel = Level::resolve($config['logging']['level'] ?? 'info');

$notifier = null;
$notifyChat = $config['logging']['notify_chat'] ?? '';

if ($notifyChat !== '' && $notifyChat !== null) {
    $notifier = new LogNotifier(
        $feeder->get(Bot::class),
        is_numeric($notifyChat) ? (int) $notifyChat : $notifyChat,
        Level::resolve($config['logging']['notify_level'] ?? 'error', Level::Error)
    );
}

$logger = new Logger(
    ($config['paths']['storage'] ?? dirname(__DIR__) . '/storage') . '/logs',
    $logLevel,
    $feeder->make(CurrentUpdate::class),
    $notifier,
    (int) ($config['logging']['keep_days'] ?? 14)
);

$feeder->set(Logger::class, $logger);
Log::use($logger);

return $feeder;
