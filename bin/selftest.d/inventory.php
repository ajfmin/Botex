<?php

/**
 * The tracked-path gate.
 *
 * This is the rule the whole "update without resetting my changes" promise
 * rests on: a core update may write inside src/, bootstrap/, public/, bin/
 * and docs/, and nowhere else. If this gate is wrong, an update can quietly
 * overwrite an operator's config or wipe their storage, which no backup
 * inside storage/ would survive.
 */

use Botex\Update\Inventory;

group('Tracked paths');

check('core code paths are tracked', static function () {
    $tracked = [
        'src/Botex.php',
        'src/Bot/Feeder.php',
        'bootstrap/app.php',
        'public/index.php',
        'bin/console',
        'docs/EXTENSIONS.md',
        'composer.json',
    ];

    foreach ($tracked as $path) {
        if (!Inventory::isTracked($path)) {
            return "'{$path}' should be tracked but is not";
        }
    }

    return true;
});

check('the operator\'s own files are never tracked', static function () {
    // Each of these is either the operator's configuration, their data, or
    // something a package manager owns. An update writing any of them is a
    // bug that loses work.
    $untracked = [
        'config/config.php',
        'config/anything.php',
        '.env',
        '.env.example',
        'storage/extensions.json',
        'storage/inventory.json',
        'storage/logs/bot.log',
        'storage/backups/x/files/src/Botex.php',
        'extensions/Clock/Extension.php',
        'extensions/Custom/Command/Mine.php',
        'vendor/autoload.php',
        'vendor/illuminate/database/src/Thing.php',
        'composer.lock',
        'hub/config.php',
        'database.sqlite',
    ];

    foreach ($untracked as $path) {
        if (Inventory::isTracked($path)) {
            return "'{$path}' must never be tracked, but is";
        }
    }

    return true;
});

check('traversal cannot reach a tracked path', static function () {
    $attempts = [
        '../src/Botex.php',
        'src/../../../etc/passwd',
        'src/../config/config.php',
        './../src/x.php',
        'docs/../../.env',
    ];

    foreach ($attempts as $path) {
        if (Inventory::isTracked($path)) {
            return "'{$path}' got past the gate";
        }
    }

    return true;
});

check('a lookalike prefix is not tracked', static function () {
    // 'srcfake/' starts with 'src' as a string but is not the src directory.
    // A prefix check written with str_starts_with and no separator lets
    // these through, so it is worth pinning.
    $attempts = [
        'srcfake/Thing.php',
        'source/Thing.php',
        'binary/x',
        'publicity/x.php',
        'documents/x.md',
        'bootstrapped/x.php',
        'composer.json.bak',
        'src',
        'bin',
    ];

    foreach ($attempts as $path) {
        if (Inventory::isTracked($path)) {
            return "'{$path}' was treated as a core path";
        }
    }

    return true;
});

check('backslash-separated paths are handled', static function () {
    // Windows hands these out; the gate must see them the same way.
    return Inventory::isTracked('src\\Bot\\Feeder.php')
        && !Inventory::isTracked('config\\config.php')
        && !Inventory::isTracked('..\\src\\x.php')
        ? true
        : 'a windows-style path was judged differently from its unix spelling';
});

check('the tracked list and the publisher agree', static function () {
    // The hub stages exactly these directories. If the two lists drift, the
    // hub ships files the bot will refuse to write, and every update reports
    // blocked paths.
    $publisher = file_get_contents(__DIR__ . '/../../hub/src/Publisher.php');

    if ($publisher === false) {
        return 'could not read hub/src/Publisher.php';
    }

    foreach (Inventory::TRACKED as $directory) {
        if (!str_contains($publisher, "'{$directory}'")) {
            return "the hub publisher does not stage '{$directory}', which the bot tracks";
        }
    }

    foreach (Inventory::TRACKED_FILES as $file) {
        if (!str_contains($publisher, "'{$file}'")) {
            return "the hub publisher does not stage '{$file}', which the bot tracks";
        }
    }

    return true;
});
