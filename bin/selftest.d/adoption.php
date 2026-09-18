<?php

/**
 * Adopting a change to a core file, and what the next release does about it.
 *
 * `core:adopt` used to mean "record the tree as the baseline", which on a
 * tree with edits in it meant recording *the operator's own bytes* as the
 * thing the core had shipped. Everything downstream then read as clean,
 * and the next release replaced the adopted file without a word -- the
 * exact outcome the whole update subsystem exists to prevent, reached by
 * the command an operator runs to prevent it.
 *
 * The fix is to keep both halves of an adopted file: what the core
 * shipped, and what the operator has. A later release is then answerable
 * rather than guessable:
 *
 *   upstream unchanged since adopting  ->  kept, untouched, no conflict
 *   upstream changed it too            ->  refuse, and say so
 *
 * Every check here runs against a real package and a real inventory on a
 * temporary tree, because the failure being guarded against is silent:
 * a wrong answer here still produces a working update, just not the tree
 * anybody asked for.
 */

use Botex\Archive\Hash;
use Botex\Archive\Package;
use Botex\Support\Config;
use Botex\Update\Inventory;
use Botex\Update\Plan;

group('Adopting core changes');

$put = static function (string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new \RuntimeException('could not create ' . dirname($path));
    }

    file_put_contents($path, $contents);
};

/**
 * An install whose baseline is exactly the files given.
 *
 * @return array{0:string, 1:Inventory}
 */
$installed = static function (string $name, array $files) use ($temp, $put): array {
    $root = $temp . '/adopt-' . $name;

    foreach ($files as $path => $contents) {
        $put($root . '/' . $path, $contents);
    }

    $inventory = new Inventory(new Config([
        'paths' => ['root' => $root, 'storage' => $root . '/storage'],
    ]));

    $hashes = [];

    foreach (array_keys($files) as $path) {
        $hashes[$path] = Hash::file($root . '/' . $path);
    }

    // Exactly as an update records it: the release's own file map.
    $inventory->adopt($hashes, '1.0.0');

    return [$root, $inventory];
};

$release = static function (string $name, array $files) use ($temp, $put): Package {
    $source = $temp . '/adopt-pkg-' . $name;

    foreach ($files as $path => $contents) {
        $put($source . '/' . $path, $contents);
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

$updater = static function (Inventory $inventory) use ($temp): \Botex\Update\CoreUpdater {
    $blank = new Config(['paths' => ['root' => $temp . '/nowhere', 'storage' => $temp . '/nowhere/storage']]);
    $client = new \Botex\Remote\Client($blank);
    $cache = new \Botex\Remote\Cache($blank);

    return new \Botex\Update\CoreUpdater(
        downloader: new \Botex\Remote\Downloader(
            $client,
            new \Botex\Remote\Catalog($client, $cache, $blank),
            new \Botex\Remote\Signature($blank)
        ),
        inventory: $inventory,
        backup: new \Botex\Update\Backup($blank),
        cache: $cache,
        log: new \Botex\Support\Log\Logger($temp . '/nowhere/logs')
    );
};

// One shipped file the operator edits, one they leave alone.
$tree = [
    'src/Botex.php' => "<?php // v1\n",
    'src/Bot/Command/Start.php' => "<?php // start v1\n",
];

check('adopting a change does not record it as what the core shipped', static function () use ($installed, $tree, $put) {
    [$root, $inventory] = $installed('baseline', $tree);

    $shipped = $inventory->hashes()['src/Bot/Command/Start.php'];

    $put($root . '/src/Bot/Command/Start.php', "<?php // start v1 + mine\n");
    $inventory->keep($inventory->dirty());

    // The bug this whole feature replaced: the baseline moving to the
    // operator's bytes, so nothing downstream could tell the two apart.
    if ($inventory->hashes()['src/Bot/Command/Start.php'] !== $shipped) {
        return 'adopting overwrote the upstream hash with the local one';
    }

    $entry = $inventory->keeping('src/Bot/Command/Start.php');

    if ($entry === null || $entry['upstream'] !== $shipped) {
        return 'the upstream half was not kept';
    }

    return $entry['mine'] === Hash::file($root . '/src/Bot/Command/Start.php')
        ? true
        : 'the adopted version was not recorded';
});

check('an adopted file stops being reported as drift', static function () use ($installed, $tree, $put) {
    [$root, $inventory] = $installed('quiet', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // mine\n");

    if ($inventory->dirty() === []) {
        return 'an edit was not noticed in the first place';
    }

    $inventory->keep($inventory->dirty());

    if ($inventory->dirty() !== []) {
        return 'an adopted file is still reported as an unresolved edit';
    }

    return $inventory->adopted() === ['src/Bot/Command/Start.php']
        ? true
        : 'the adopted file was not reported as adopted';
});

check('editing an adopted file again makes it drift again', static function () use ($installed, $tree, $put) {
    [$root, $inventory] = $installed('again', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // mine\n");
    $inventory->keep($inventory->dirty());

    // What was blessed is one version of the file, not the path itself.
    $put($root . '/src/Bot/Command/Start.php', "<?php // mine, revised\n");

    if ($inventory->dirty() !== ['src/Bot/Command/Start.php']) {
        return 'a later edit to an adopted file went unnoticed';
    }

    return $inventory->adopted() === [] ? true : 'a stale adoption was still reported as intact';
});

check('a release that leaves an adopted file alone keeps it', static function () use ($installed, $release, $updater, $tree, $put) {
    [$root, $inventory] = $installed('keep', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // start v1 + mine\n");
    $inventory->keep($inventory->dirty());

    // Upstream moved Botex.php and left Start.php exactly as it was.
    $plan = $updater($inventory)->plan($release('keep', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v1\n",
    ]));

    if ($plan->conflicts() !== []) {
        return 'an untouched adopted file was reported as a conflict';
    }

    if ($plan->paths(Plan::KEPT) !== ['src/Bot/Command/Start.php']) {
        return 'the adopted file was not kept: ' . $plan->summary();
    }

    if (in_array('src/Bot/Command/Start.php', $plan->paths(Plan::REPLACE), true)) {
        return 'the adopted file was planned for replacement';
    }

    return $plan->paths(Plan::REPLACE) === ['src/Botex.php']
        ? true
        : 'the rest of the release stopped being applied: ' . $plan->summary();
});

check('a release that changes an adopted file is refused', static function () use ($installed, $release, $updater, $tree, $put) {
    [$root, $inventory] = $installed('refuse', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // start v1 + mine\n");
    $inventory->keep($inventory->dirty());

    $plan = $updater($inventory)->plan($release('refuse', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2, rewritten upstream\n",
    ]));

    if ($plan->conflicts() !== ['src/Bot/Command/Start.php']) {
        return 'upstream changing an adopted file did not conflict: ' . $plan->summary();
    }

    // A conflict stops the whole update, not just that file -- half a
    // core is worse than none.
    return $plan->isSafe() ? 'the plan still reported itself as safe' : true;
});

check('a release that deletes an adopted file is refused', static function () use ($installed, $release, $updater, $tree, $put) {
    [$root, $inventory] = $installed('drop', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // start v1 + mine\n");
    $inventory->keep($inventory->dirty());

    // Removing a file is a change to it like any other.
    $plan = $updater($inventory)->plan($release('drop', [
        'src/Botex.php' => "<?php // v2\n",
    ]));

    if (in_array('src/Bot/Command/Start.php', $plan->paths(Plan::DELETE), true)) {
        return 'an adopted file was planned for deletion';
    }

    return $plan->conflicts() === ['src/Bot/Command/Start.php']
        ? true
        : 'dropping an adopted file did not conflict: ' . $plan->summary();
});

check('a file deleted on purpose is not put back', static function () use ($installed, $release, $updater, $tree) {
    [$root, $inventory] = $installed('deleted', $tree);

    unlink($root . '/src/Bot/Command/Start.php');
    $inventory->keep($inventory->dirty());

    $plan = $updater($inventory)->plan($release('deleted', [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v1\n",
    ]));

    if (in_array('src/Bot/Command/Start.php', $plan->paths(Plan::ADD), true)) {
        return 'a file the operator deleted on purpose was helpfully restored';
    }

    return $plan->paths(Plan::KEPT) === ['src/Bot/Command/Start.php']
        ? true
        : 'the deletion was not kept: ' . $plan->summary();
});

group('Resetting the core');

check('a reset plans every file, adoptions included', static function () use ($installed, $release, $updater, $tree, $put) {
    [$root, $inventory] = $installed('reset', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // start v1 + mine\n");
    $inventory->keep($inventory->dirty());

    // A file the core never shipped, at a path the release now wants:
    // an ordinary update refuses this outright.
    $put($root . '/src/Bot/Command/Admin.php', "<?php // mine outright\n");

    $files = [
        'src/Botex.php' => "<?php // v2\n",
        'src/Bot/Command/Start.php' => "<?php // start v2\n",
        'src/Bot/Command/Admin.php' => "<?php // admin v2\n",
    ];

    $ordinary = $updater($inventory)->plan($release('reset', $files));

    if ($ordinary->conflicts() === [] || $ordinary->collisions() === []) {
        return 'the scenario did not produce the refusals a reset is meant to answer';
    }

    $reset = $updater($inventory)->plan($release('reset', $files), reset: true);

    foreach (['conflicts', 'collisions'] as $refusal) {
        if ($reset->$refusal() !== []) {
            return "a reset still reported {$refusal}";
        }
    }

    // Every one of them is simply written.
    $writes = array_merge($reset->paths(Plan::REPLACE), $reset->paths(Plan::ADD));
    sort($writes);

    return $writes === array_keys($files) || $writes === ['src/Bot/Command/Admin.php', 'src/Bot/Command/Start.php', 'src/Botex.php']
        ? true
        : 'a reset did not plan to write everything: ' . implode(', ', $writes);
});

check('a reset may go back to an older release', static function () use ($installed, $release, $updater, $tree) {
    [$root, $inventory] = $installed('downgrade', $tree);

    // plan() refuses a downgrade for an ordinary update, because going
    // backwards by accident is a real way to lose a release. Asking for a
    // specific version explicitly is not an accident.
    $older = $release('downgrade', ['src/Botex.php' => "<?php // v0\n"]);

    $reset = $updater($inventory)->plan($older, reset: true);

    foreach ($reset->problems() as $problem) {
        if (str_contains($problem, 'downgrade')) {
            return 'a reset refused to go backwards';
        }
    }

    return true;
});

check('a reset still refuses what is not the operator to decide', static function () use ($installed, $release, $updater, $tree) {
    [$root, $inventory] = $installed('guard', $tree);

    // Writing outside the core surface is a malformed or hostile package,
    // and no flag makes it acceptable.
    $hostile = $release('guard', [
        'src/Botex.php' => "<?php // v2\n",
        'config/config.php' => "<?php // yours now\n",
    ]);

    $plan = $updater($inventory)->plan($hostile, reset: true);

    return $plan->blocked() === ['config/config.php']
        ? true
        : 'a reset let a package write outside the core surface';
});

check('a reset forgets the adoptions it overwrote', static function () use ($installed, $tree, $put) {
    [$root, $inventory] = $installed('forget', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // mine\n");
    $inventory->keep($inventory->dirty());

    if ($inventory->kept() === []) {
        return 'nothing was adopted to begin with';
    }

    // What apply() does at the end of a reset: the release's own file map
    // becomes the whole truth about the tree.
    $inventory->adopt(['src/Botex.php' => str_repeat('a', 64)], '2.0.0');

    return $inventory->kept() === []
        ? true
        : 'an adoption survived a reset and would refuse the next update';
});

check('a partial write keeps the structure around it', static function () use ($installed, $tree, $put) {
    [$root, $inventory] = $installed('partial', $tree);

    $put($root . '/src/Bot/Command/Start.php', "<?php // mine\n");
    $inventory->keep($inventory->dirty());

    // merge() and forget() name only what moved. They used to replace the
    // whole record, which dropped every key they did not mention -- that
    // is how `scoped` disappeared at the first update after it was set,
    // silently turning deletions off for good.
    $inventory->merge(['src/Botex.php' => str_repeat('b', 64)], '1.1.0');

    if (!$inventory->isScoped()) {
        return 'a merge dropped the scoped flag';
    }

    if ($inventory->kept() === []) {
        return 'a merge dropped the adoptions';
    }

    $inventory->forget(['src/Botex.php'], '1.1.0');

    return $inventory->isScoped() && $inventory->kept() !== []
        ? true
        : 'a forget dropped the structure around it';
});
