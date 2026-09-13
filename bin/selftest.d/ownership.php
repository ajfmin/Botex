<?php

/**
 * Who owns which file, and what an update is therefore allowed to touch.
 *
 * The rule these checks exist to hold: a file under src/ belongs to Botex
 * only if a Botex release shipped it. Everything else there is the
 * operator's -- a command they wrote beside the shipped ones -- and an
 * update must leave it exactly as it found it.
 *
 * Getting this wrong is silent and expensive: the old rule was "src/ is
 * ours", which adopted a custom command as core and then deleted it on the
 * first release that did not ship one by that name.
 */

use Botex\Archive\Hash;
use Botex\Archive\Package;
use Botex\Bot\Command\Discovery;
use Botex\Update\CoreManifest;
use Botex\Update\Inventory;
use Botex\Update\Plan;
use Botex\Support\Config;

group('File ownership');

/** Writes a file, creating its directory. */
$write = static function (string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new \RuntimeException('could not create ' . dirname($path));
    }

    file_put_contents($path, $contents);
};

/**
 * A throwaway install: a root with a core tree, and an Inventory over it.
 *
 * @return array{0:string, 1:Inventory, 2:CoreManifest}
 */
$install = static function (string $name, array $files) use ($temp, $write): array {
    $root = $temp . '/own-' . $name;

    foreach ($files as $path => $contents) {
        $write($root . '/' . $path, $contents);
    }

    $config = new Config([
        'paths' => ['root' => $root, 'storage' => $root . '/storage'],
    ]);

    return [$root, new Inventory($config), new CoreManifest($config)];
};

/** A real core package over a set of files. */
$package = static function (string $name, array $files) use ($temp, $write): Package {
    $source = $temp . '/pkg-' . $name;

    foreach ($files as $path => $contents) {
        $write($source . '/' . $path, $contents);
    }

    return Package::fromString(Package::build(
        directory: $source,
        type: 'core',
        slug: 'core',
        name: 'Botex',
        version: '9.9.9',
        description: 'test core'
    ));
};

// The tree every scenario starts from: two shipped commands, and two the
// operator wrote next to them.
$tree = [
    'src/Botex.php' => "<?php // v1\n",
    'src/Bot/Command/Start.php' => "<?php // start v1\n",
    'src/Bot/Command/Admin.php' => "<?php // admin v1\n",
    'src/Bot/Command/Profile.php' => "<?php // mine\n",
    'src/Bot/Command/BuyServer.php' => "<?php // mine too\n",
    'core.json' => '{}',
];

$shipped = ['core.json', 'src/Botex.php', 'src/Bot/Command/Start.php', 'src/Bot/Command/Admin.php'];

check('adopt records only what the release shipped', static function () use ($install, $tree, $shipped) {
    [, $inventory, $manifest] = $install('adopt', $tree);
    $manifest->write($shipped, '1.0.0');

    $inventory->record('1.0.0', $manifest->paths());
    $recorded = array_keys($inventory->hashes());

    foreach (['src/Bot/Command/Profile.php', 'src/Bot/Command/BuyServer.php'] as $mine) {
        if (in_array($mine, $recorded, true)) {
            return "{$mine} was adopted as a core file";
        }
    }

    sort($shipped);

    return $recorded === $shipped
        ? true
        : 'recorded: ' . implode(', ', $recorded);
});

check('the custom files are reported as the operator\'s', static function () use ($install, $tree, $shipped) {
    [, $inventory, $manifest] = $install('mine', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    $mine = $inventory->userOwned();
    sort($mine);

    return $mine === ['src/Bot/Command/BuyServer.php', 'src/Bot/Command/Profile.php']
        ? true
        : 'reported: ' . implode(', ', $mine);
});

check('ownership is by record, not by directory', static function () use ($install, $tree, $shipped) {
    [, $inventory, $manifest] = $install('owns', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    if (!$inventory->owns('src/Bot/Command/Start.php')) {
        return 'a shipped command was not recognised as core-owned';
    }

    return !$inventory->owns('src/Bot/Command/Profile.php')
        ? true
        : 'a custom command in a core directory was treated as core-owned';
});

check('a manifest cannot widen the core surface', static function () use ($install, $tree) {
    [, , $manifest] = $install('widen', $tree);

    // A manifest edited to claim configuration must not make config/ or
    // .env core-owned, whatever it says.
    $manifest->write(['src/Botex.php', 'config/config.php', '.env', '../escape.php'], '1.0.0');

    $paths = $manifest->paths();

    foreach (['config/config.php', '.env', '../escape.php'] as $forbidden) {
        if (in_array($forbidden, $paths, true)) {
            return "'{$forbidden}' got into the manifest";
        }
    }

    return in_array('src/Botex.php', $paths, true) ? true : 'the real path was dropped too';
});

check('the manifest always lists itself', static function () use ($install, $tree) {
    [, , $manifest] = $install('self', $tree);
    $manifest->write(['src/Botex.php'], '1.0.0');

    // Otherwise core.json is user-owned, and the next release that ships
    // one collides with it -- blocking every update.
    return in_array(CoreManifest::FILE, $manifest->paths(), true)
        ? true
        : 'the manifest left itself out';
});

group('Core updates and user files');

/**
 * An updater whose only real collaborator is the inventory.
 *
 * plan() reads nothing else -- it decides entirely from what was recorded
 * and what the package carries -- so the rest are built as cheaply as
 * their constructors allow rather than mocked.
 */
$plan = static function (Inventory $inventory, Package $package, ?CoreManifest $manifest = null) use ($temp): Plan {
    $blank = new Config(['paths' => ['root' => $temp . '/nowhere', 'storage' => $temp . '/nowhere/storage']]);
    $client = new \Botex\Remote\Client($blank);
    $cache = new \Botex\Remote\Cache($blank);

    $updater = new \Botex\Update\CoreUpdater(
        downloader: new \Botex\Remote\Downloader(
            $client,
            new \Botex\Remote\Catalog($client, $cache, $blank),
            new \Botex\Remote\Signature($blank)
        ),
        inventory: $inventory,
        // The install's own manifest when there is one: the legacy
        // path reads it to decide what an old inventory really owned.
        manifest: $manifest ?? new CoreManifest($blank),
        backup: new \Botex\Update\Backup($blank),
        cache: $cache,
        log: new \Botex\Support\Log\Logger($temp . '/nowhere/logs')
    );

    return $updater->plan($package);
};

check('a custom command survives an update', static function () use ($install, $package, $tree, $shipped, $plan) {
    [$root, $inventory, $manifest] = $install('survive', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    // The next release: the same two commands, changed, plus a new one.
    $release = $package('survive', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
        'src/Bot/Command/Wallet.php' => "<?php // wallet v2\n",
    ]);

    $result = $plan($inventory, $release);

    foreach (['src/Bot/Command/Profile.php', 'src/Bot/Command/BuyServer.php'] as $mine) {
        foreach ([Plan::DELETE, Plan::REPLACE, Plan::CONFLICT, Plan::COLLISION] as $action) {
            if (in_array($mine, $result->paths($action), true)) {
                return "{$mine} was planned for {$action}";
            }
        }

        if (!is_file($root . '/' . $mine)) {
            return "{$mine} is gone from disk";
        }
    }

    return $result->isSafe() ? true : 'the plan was not safe: ' . implode('; ', $result->problems());
});

check('core files are updated and new ones added', static function () use ($install, $package, $tree, $shipped, $plan) {
    [, $inventory, $manifest] = $install('update', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    $release = $package('update', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
        'src/Bot/Command/Wallet.php' => "<?php // wallet v2\n",
    ]);

    $result = $plan($inventory, $release);

    foreach (['src/Bot/Command/Start.php', 'src/Bot/Command/Admin.php'] as $core) {
        if (!in_array($core, $result->paths(Plan::REPLACE), true)) {
            return "{$core} was not planned for replacement";
        }
    }

    return in_array('src/Bot/Command/Wallet.php', $result->paths(Plan::ADD), true)
        ? true
        : 'the new core command was not planned as an addition';
});

check('a core file the release drops is deleted', static function () use ($install, $package, $tree, $plan) {
    [, $inventory, $manifest] = $install('drop', $tree);

    // Admin.php was shipped by the installed version...
    $manifest->write(['core.json', 'src/Botex.php', 'src/Bot/Command/Start.php', 'src/Bot/Command/Admin.php'], '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    // ...and is gone from the next one.
    $release = $package('drop', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
    ]);

    $result = $plan($inventory, $release);

    return in_array('src/Bot/Command/Admin.php', $result->paths(Plan::DELETE), true)
        ? true
        : 'a command dropped upstream was not deleted: ' . $result->summary();
});

check('a file the core never shipped is never deleted', static function () use ($install, $package, $tree, $shipped, $plan) {
    [, $inventory, $manifest] = $install('keep', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    // A release that ships almost nothing: every recorded file is absent
    // from it, but the operator's are not the core's to remove.
    $release = $package('keep', ['src/Botex.php' => "<?php // v2\n"]);

    $result = $plan($inventory, $release);

    foreach ($result->paths(Plan::DELETE) as $path) {
        if (str_contains($path, 'Profile') || str_contains($path, 'BuyServer')) {
            return "{$path} was planned for deletion";
        }
    }

    return true;
});

check('a release landing on a user file is refused', static function () use ($install, $package, $tree, $shipped, $plan) {
    [$root, $inventory, $manifest] = $install('collide', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    // The operator's own Stats.php, and a release that introduces one.
    file_put_contents($root . '/src/Bot/Command/Stats.php', "<?php // mine\n");

    $release = $package('collide', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
        'src/Bot/Command/Stats.php' => "<?php // theirs\n",
    ]);

    $result = $plan($inventory, $release);

    if (!in_array('src/Bot/Command/Stats.php', $result->collisions(), true)) {
        return 'the collision was not detected: ' . $result->summary();
    }

    if ($result->isSafe()) {
        return 'a plan with a collision was still considered safe';
    }

    // And nothing was written: planning never touches the disk.
    return file_get_contents($root . '/src/Bot/Command/Stats.php') === "<?php // mine\n"
        ? true
        : 'the user file changed while planning';
});

check('an inventory from an older core cannot delete user files', static function () use (
    $install,
    $package,
    $tree,
    $shipped,
    $plan
) {
    [, $inventory, $manifest] = $install('legacy', $tree);
    $manifest->write($shipped, '1.0.0');

    // What a core older than this rule wrote: every file on disk, the
    // operator's two commands included, and no 'scoped' marker.
    $inventory->record('1.0.0');

    if ($inventory->isScoped()) {
        return 'a whole-tree record claimed to be scoped';
    }

    if (!$inventory->owns('src/Bot/Command/Profile.php')) {
        return 'the legacy record was expected to name the user file';
    }

    // A release that ships neither of them. Under the old rule both would
    // be deleted; the installed manifest says they were never ours.
    $release = $package('legacy', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
    ]);

    $deletions = $plan($inventory, $release, $manifest)->paths(Plan::DELETE);

    foreach (['src/Bot/Command/Profile.php', 'src/Bot/Command/BuyServer.php'] as $mine) {
        if (in_array($mine, $deletions, true)) {
            return "{$mine} would be deleted on the first update after upgrading";
        }
    }

    return true;
});

check('a scoped record says so', static function () use ($install, $tree, $shipped) {
    [, $inventory, $manifest] = $install('scoped', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    return $inventory->isScoped() ? true : 'a narrowed record did not mark itself';
});

check('an edited core file is still a conflict', static function () use ($install, $package, $tree, $shipped, $plan) {
    [$root, $inventory, $manifest] = $install('edited', $tree);
    $manifest->write($shipped, '1.0.0');
    $inventory->record('1.0.0', $manifest->paths());

    // The operator edits a file the core owns, and upstream changes it too.
    file_put_contents($root . '/src/Bot/Command/Start.php', "<?php // my edit\n");

    $release = $package('edited', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
    ]);

    $result = $plan($inventory, $release);

    if (!in_array('src/Bot/Command/Start.php', $result->conflicts(), true)) {
        return 'an edited core file was not reported as a conflict';
    }

    // A conflict, not a collision: the difference is whether the core has
    // a version of the file to fall back to.
    return $result->collisions() === []
        ? true
        : 'an edited core file was mistaken for a user-owned one';
});

group('Custom command discovery');

/** A scratch command directory with the given files in it. */
$commands = static function (string $name, array $files) use ($temp, $write): Discovery {
    $directory = $temp . '/cmd-' . $name;

    foreach ($files as $file => $contents) {
        $write($directory . '/' . $file, $contents);
    }

    return Discovery::in($directory);
};

check('the shipped commands are discovered from the real directory', static function () {
    // Against the actual src/Bot/Command/, which is what ships: core's own
    // classes are excluded, so a stock checkout discovers nothing.
    $found = (new Discovery())->commands();

    return $found === []
        ? true
        : 'a core class was returned as custom: ' . implode(', ', $found);
});

check('a valid custom command is found', static function () use ($commands) {
    // Declared here rather than written to disk: the discovery asks the
    // autoloader, and an eval'd class is already loaded under the name the
    // file would have produced.
    if (!class_exists('Botex\Bot\Command\SelftestProfile')) {
        eval('namespace Botex\Bot\Command; class SelftestProfile implements CommandInterface {
            public static function command(): string { return "/selftestprofile"; }
            public static function button(): string { return "p"; }
            public static function middleware(): array { return []; }
            public function handle(\Botex\Telegram\Update $update): void {}
        }');
    }

    $found = $commands('valid', ['SelftestProfile.php' => "<?php\n"])->commands();

    return $found === ['Botex\Bot\Command\SelftestProfile']
        ? true
        : 'found: ' . implode(', ', $found);
});

check('unrelated classes in the directory are ignored', static function () use ($commands) {
    foreach ([
        'SelftestHelper' => 'class SelftestHelper { }',
        'SelftestAbstract' => 'abstract class SelftestAbstract implements CommandInterface {
            public static function command(): string { return "/x"; }
            public static function button(): string { return "x"; }
            public static function middleware(): array { return []; }
            public function handle(\Botex\Telegram\Update $update): void {}
        }',
        'SelftestContract' => 'interface SelftestContract { }',
        'SelftestTrait' => 'trait SelftestTrait { }',
    ] as $name => $code) {
        if (!class_exists('Botex\Bot\Command\\' . $name) && !interface_exists('Botex\Bot\Command\\' . $name)) {
            eval('namespace Botex\Bot\Command; ' . $code);
        }
    }

    $found = $commands('junk', [
        'SelftestHelper.php' => "<?php\n",
        'SelftestAbstract.php' => "<?php\n",
        'SelftestContract.php' => "<?php\n",
        'SelftestTrait.php' => "<?php\n",
        'notes.txt' => "not php\n",
        'README.md' => "not php\n",
    ])->commands();

    return $found === []
        ? true
        : 'something unrelated was treated as a command: ' . implode(', ', $found);
});

check('a file whose class does not exist is skipped', static function () use ($commands) {
    // A file in the right place holding a class in the wrong namespace:
    // PSR-4 would never find it, so neither does discovery.
    $found = $commands('missing', ['SelftestNowhere.php' => "<?php\nnamespace Other; class SelftestNowhere {}\n"])
        ->commands();

    return $found === [] ? true : 'found: ' . implode(', ', $found);
});

check('core commands are registered exactly once', static function () {
    $registry = new \Botex\Extension\Registry(
        sys_get_temp_dir() . '/botex-selftest-noext',
        new \Botex\Extension\State(sys_get_temp_dir() . '/botex-selftest-noext-state.json')
    );

    $commands = new \Botex\Bot\Command\Commands($registry, new Discovery());
    $all = $commands->all();

    if (count($all) !== count(array_unique($all))) {
        return 'a command class appears twice: ' . implode(', ', $all);
    }

    // And the fallback is not typeable, even though it is a command class
    // sitting in the same directory.
    return $commands->find('unknown') === null
        ? true
        : 'the unknown-command fallback became a real verb';
});

check('core wins a verb an addition would take', static function () use ($commands) {
    if (!class_exists('Botex\Bot\Command\SelftestStart')) {
        eval('namespace Botex\Bot\Command; class SelftestStart implements CommandInterface {
            public static function command(): string { return "/start"; }
            public static function button(): string { return "s"; }
            public static function middleware(): array { return []; }
            public function handle(\Botex\Telegram\Update $update): void {}
        }');
    }

    $registry = new \Botex\Extension\Registry(
        sys_get_temp_dir() . '/botex-selftest-noext',
        new \Botex\Extension\State(sys_get_temp_dir() . '/botex-selftest-noext-state.json')
    );

    $commands = new \Botex\Bot\Command\Commands(
        $registry,
        $commands('shadow', ['SelftestStart.php' => "<?php\n"])
    );

    return $commands->find('start') === \Botex\Bot\Command\Start::class
        ? true
        : 'a custom command displaced /start: ' . (string) $commands->find('start');
});
