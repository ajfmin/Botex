<?php

/**
 * The guards that stop a bad update, checked by trying to get past them.
 *
 * These are the ones with no second chance. A core update rewrites the code
 * performing the update, so a package that guts the tree cannot be noticed
 * afterwards -- there is nothing left to notice with.
 */

use Botex\Archive\Manifest;
use Botex\Archive\Package;
use Botex\Update\Inventory;
use Botex\Update\Plan;

group('Refusals');

check('a package that would delete most of the core is refused', static function () use ($temp) {
    // Simulates the real failure: someone publishes a fragment -- a handful
    // of files -- as a full core release. Every other core file then looks
    // "removed upstream", and applying it leaves an install that cannot boot.
    $recorded = [];

    for ($i = 0; $i < 140; $i++) {
        $recorded["src/File{$i}.php"] = str_repeat('a', 64);
    }

    $plan = new Plan('core', '1.0.0', '2.0.0');

    // The fragment ships three files and drops the rest.
    $plan->add(Plan::REPLACE, 'src/File0.php');
    $plan->add(Plan::REPLACE, 'src/File1.php');
    $plan->add(Plan::REPLACE, 'src/File2.php');

    for ($i = 3; $i < 140; $i++) {
        $plan->add(Plan::DELETE, "src/File{$i}.php");
    }

    // guardAgainstGutting is private, so the rule is exercised through the
    // same reflection the updater would reach it by. Testing the behaviour
    // matters more than testing it via a public seam that does not exist.
    $updater = (new \ReflectionClass(\Botex\Update\CoreUpdater::class))
        ->newInstanceWithoutConstructor();

    $guard = new \ReflectionMethod($updater, 'guardAgainstGutting');
    $guard->invoke($updater, $plan, $recorded);

    if ($plan->isSafe()) {
        return 'a package deleting 137 of 140 core files was judged safe';
    }

    foreach ($plan->problems() as $problem) {
        if (str_contains($problem, 'delete')) {
            return true;
        }
    }

    return 'it was refused, but not for the reason expected';
});

check('an ordinary release that drops a few files is not refused', static function () {
    // The counterpart to the check above: the guard must not fire on a real
    // release, or it becomes something operators learn to force past.
    $recorded = [];

    for ($i = 0; $i < 140; $i++) {
        $recorded["src/File{$i}.php"] = str_repeat('a', 64);
    }

    $plan = new Plan('core', '1.0.0', '1.1.0');

    for ($i = 0; $i < 137; $i++) {
        $plan->add(Plan::REPLACE, "src/File{$i}.php");
    }

    $plan->add(Plan::DELETE, 'src/File137.php');
    $plan->add(Plan::DELETE, 'src/File138.php');
    $plan->add(Plan::DELETE, 'src/File139.php');

    $updater = (new \ReflectionClass(\Botex\Update\CoreUpdater::class))
        ->newInstanceWithoutConstructor();

    (new \ReflectionMethod($updater, 'guardAgainstGutting'))
        ->invoke($updater, $plan, $recorded);

    return $plan->isSafe()
        ? true
        : 'a normal release was refused: ' . implode('; ', $plan->problems());
});

check('a package may not write outside the core surface', static function () use ($temp) {
    // The gate applied to every path in a package, checked here as a set
    // rather than one path at a time.
    $shipped = [
        'src/Botex.php' => true,
        'bin/console' => true,
        'composer.json' => true,
        'config/config.php' => false,
        '.env' => false,
        'storage/logs/bot.log' => false,
        'extensions/Mine/Extension.php' => false,
        'vendor/autoload.php' => false,
        '../outside.php' => false,
    ];

    foreach ($shipped as $path => $allowed) {
        if (Inventory::isTracked($path) !== $allowed) {
            return "'{$path}' was judged " . (Inventory::isTracked($path) ? 'writable' : 'blocked')
                . ', which is wrong';
        }
    }

    return true;
});

group('Backups');

check('a backup label cannot escape the backups directory', static function () use ($temp) {
    // The label comes from user input on core:rollback, so it decides which
    // directory gets read and, on delete, removed.
    $attempts = ['../../src', '..', '/etc', 'a/../../b', 'x/../..', './..'];
    $backup = (new \ReflectionClass(\Botex\Update\Backup::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($backup, 'label');

    foreach ($attempts as $attempt) {
        try {
            $method->invoke($backup, $attempt);
        } catch (\Throwable) {
            continue;
        }

        return "'{$attempt}' was accepted as a backup label";
    }

    return true;
});

check('a real backup round-trips a file', static function () use ($temp) {
    // Built against a temporary root so nothing here touches the install.
    $root = $temp . '/backup-root';

    @mkdir($root . '/src', 0775, true);
    @mkdir($root . '/storage', 0775, true);

    $file = $root . '/src/Thing.php';
    file_put_contents($file, "<?php\n\n// original\n");

    $backup = new \Botex\Update\Backup(new class ($root) extends \Botex\Support\Config {
        public function __construct(private string $root)
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return match ($key) {
                'paths.root' => $this->root,
                'paths.storage' => $this->root . '/storage',
                default => $default,
            };
        }
    });

    $label = $backup->begin('selftest');
    $backup->keep($label, 'src/Thing.php');

    // The update overwrites it, then the rollback puts the original back.
    file_put_contents($file, "<?php\n\n// overwritten by an update\n");

    $restored = $backup->restore($label);

    if ($restored !== ['src/Thing.php']) {
        return 'restore() reported ' . var_export($restored, true);
    }

    return str_contains((string) file_get_contents($file), '// original')
        ? true
        : 'the file was not restored to its original contents';
});
