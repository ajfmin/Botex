<?php

/**
 * The extension registry's cached scan.
 *
 * Registry memoises its directory scan per process, which is right for a
 * webhook and wrong for an installer: the installer writes a new folder and
 * then asks whether it is there. Getting an answer from before the write is
 * how a first install silently skips the extension's own install(), leaving
 * the files in place and its tables never created.
 */

use Botex\Extension\Registry;
use Botex\Extension\State;

group('Extension registry');

/** A registry over an empty scratch directory, with real State. */
$makeRegistry = static function (string $root) use ($temp): Registry {
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new \RuntimeException("could not create {$root}");
    }

    return new Registry($root, new State($temp . '/state-' . bin2hex(random_bytes(3)) . '.json'));
};

/** Writes a minimal but valid extension folder. */
$writeExtension = static function (string $root, string $slug, string $version = '1.0.0'): void {
    $dir = $root . '/' . $slug;

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException("could not create {$dir}");
    }

    file_put_contents($dir . '/extension.json', json_encode([
        'name' => $slug,
        'version' => $version,
        'entry' => "Extensions\\{$slug}\\Extension",
    ]));

    file_put_contents($dir . '/Extension.php', "<?php\n");
};

check('an extension written after the first scan is invisible until refresh', static function () use (
    $makeRegistry,
    $writeExtension,
    $temp
) {
    $root = $temp . '/registry-stale/extensions';
    $registry = $makeRegistry($root);

    // The installer's own first question: is it already on disk? This is
    // what populates the cache at the worst possible moment.
    if ($registry->find('Ghost') !== null) {
        return 'an empty directory reported an extension';
    }

    $writeExtension($root, 'Ghost');

    if ($registry->find('Ghost') !== null) {
        return 'the cache was not actually caching, so this test proves nothing';
    }

    $registry->refresh();

    $found = $registry->find('Ghost');

    if ($found === null) {
        return 'still invisible after refresh(), so an installer cannot run install()';
    }

    return $found->version === '1.0.0'
        ? true
        : "refresh() found version '{$found->version}', expected 1.0.0";
});

check('refresh() picks up a replaced version rather than the old one', static function () use (
    $makeRegistry,
    $writeExtension,
    $temp
) {
    $root = $temp . '/registry-replaced/extensions';
    $registry = $makeRegistry($root);

    $writeExtension($root, 'Shop', '1.0.0');

    if ($registry->find('Shop')?->version !== '1.0.0') {
        return 'the first scan did not see version 1.0.0';
    }

    // What an update does: the folder is swapped for a newer one.
    $writeExtension($root, 'Shop', '2.0.0');
    $registry->refresh();

    $version = $registry->find('Shop')?->version;

    return $version === '2.0.0' ? true : "saw '{$version}' after refresh(), expected 2.0.0";
});

check('a removed extension disappears after refresh', static function () use (
    $makeRegistry,
    $writeExtension,
    $temp
) {
    $root = $temp . '/registry-removed/extensions';
    $registry = $makeRegistry($root);

    $writeExtension($root, 'Doomed');

    if ($registry->find('Doomed') === null) {
        return 'the extension was not found before removal';
    }

    @unlink($root . '/Doomed/extension.json');
    @unlink($root . '/Doomed/Extension.php');
    @rmdir($root . '/Doomed');

    $registry->refresh();

    return $registry->find('Doomed') === null
        ? true
        : 'a deleted extension is still listed, so a reinstall would be refused as present';
});

check('refresh() clears an error from a scan that no longer applies', static function () use (
    $makeRegistry,
    $writeExtension,
    $temp
) {
    $root = $temp . '/registry-errors/extensions';
    $registry = $makeRegistry($root);

    $dir = $root . '/Broken';

    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        return "could not create {$dir}";
    }

    file_put_contents($dir . '/extension.json', '{ this is not json');

    if (!isset($registry->errors()['Broken'])) {
        return 'an unparseable extension.json was not recorded as an error';
    }

    // The installer replaces the folder with a good copy.
    $writeExtension($root, 'Broken');
    $registry->refresh();

    if (isset($registry->errors()['Broken'])) {
        return 'the stale error survived refresh(), so a fixed extension still reads as broken';
    }

    return $registry->find('Broken') !== null
        ? true
        : 'the repaired extension is not visible';
});

check('the installer refreshes the registry after swapping files', static function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/Update/ExtensionInstaller.php');

    if ($source === false) {
        return 'could not read ExtensionInstaller.php';
    }

    // The bug this guards against: Cache::flush() was called here instead,
    // which clears archive HTTP responses on disk and does nothing to the
    // Registry's in-memory scan. It looked right and fixed nothing.
    if (!str_contains($source, '$this->registry->refresh()')) {
        return 'ExtensionInstaller does not call registry->refresh(), '
            . 'so a first install cannot see the extension it just wrote';
    }

    $swap = strpos($source, '$this->deleteDirectory($retired)');
    $refresh = strpos($source, '$this->registry->refresh()');
    $migrate = strpos($source, '$migrated = $this->migrate(');

    if ($swap === false || $refresh === false || $migrate === false) {
        return 'could not locate the swap, the refresh and the migrate call';
    }

    return $refresh > $swap && $refresh < $migrate
        ? true
        : 'refresh() must happen after the files are in place and before migrate()';
});

check('a failed migrate reports the recorded reason, not a guess', static function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/Update/ExtensionInstaller.php');

    if ($source === false) {
        return 'could not read ExtensionInstaller.php';
    }

    // "check extension.json" was reported for a stale cache, which sent
    // whoever read it looking at a file that was perfectly fine.
    if (str_contains($source, 'is not readable after installing; check extension.json')) {
        return 'the old guessed message is still there';
    }

    return str_contains($source, '$this->registry->errors()[$slug]')
        ? true
        : 'migrate() does not consult Registry::errors() for the real reason';
});
