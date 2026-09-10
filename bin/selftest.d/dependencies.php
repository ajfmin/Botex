<?php

/**
 * Extension-to-extension requirements.
 *
 * An extension built on another one is broken in an unreadable way when
 * the one below it is missing or switched off -- a class that will not
 * autoload, on whatever message happens to arrive first. These checks
 * cover the two halves of the guard that turns that into a sentence:
 * reading `requires.extensions` out of a manifest, and answering what is
 * unmet and who would be left dangling.
 */

use Botex\Extension\Dependencies;
use Botex\Extension\Manifest;
use Botex\Extension\Registry;
use Botex\Extension\State;

group('Extension dependencies');

/** Writes an extension folder, optionally requiring others. */
$writeExtension = static function (
    string $root,
    string $slug,
    string $version = '1.0.0',
    array $requires = []
): void {
    $dir = $root . '/' . $slug;

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException("could not create {$dir}");
    }

    $manifest = [
        'name' => $slug,
        'version' => $version,
        'entry' => "Extensions\\{$slug}\\Extension",
    ];

    if ($requires !== []) {
        $manifest['requires'] = ['php' => '>=8.2', 'extensions' => $requires];
    }

    file_put_contents($dir . '/extension.json', json_encode($manifest));
    file_put_contents($dir . '/Extension.php', "<?php\n");
};

/** A registry and its state, over a scratch directory. */
$build = static function (string $name) use ($temp, $writeExtension): array {
    $root = $temp . '/deps-' . $name . '/extensions';

    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new \RuntimeException("could not create {$root}");
    }

    $writeExtension($root, 'Shop', '1.2.0');
    $writeExtension($root, 'Addon', '1.0.0', ['Shop' => '>=1.1.0']);
    $writeExtension($root, 'Loner', '1.0.0');

    $state = new State($temp . '/deps-' . $name . '-state.json');

    return [new Registry($root, $state), $state];
};

check('a manifest with no requires has none', static function () use ($build) {
    [$registry] = $build('none');

    return $registry->find('Loner')?->requires === []
        ? true
        : 'an extension with no requires reported some';
});

check('requires.extensions is read', static function () use ($build) {
    [$registry] = $build('read');
    $addon = $registry->find('Addon');

    if ($addon === null) {
        return 'the addon was not found';
    }

    if (!$addon->requiresExtension('Shop')) {
        return 'the requirement on Shop was lost';
    }

    return ($addon->requires['Shop'] ?? '') === '>=1.1.0'
        ? true
        : "the constraint came back as '" . ($addon->requires['Shop'] ?? '') . "'";
});

check('php and botex are left to the archive', static function () use ($build) {
    [$registry] = $build('archive');

    // `requires` also carries php and botex, which mean nothing to a bot
    // that is already running; only the extensions key is read here.
    return array_keys($registry->find('Addon')?->requires ?? []) === ['Shop']
        ? true
        : 'a non-extension requirement leaked into the map';
});

check('a met requirement reports nothing', static function () use ($build) {
    [$registry] = $build('met');
    $dependencies = new Dependencies($registry);

    $problems = $dependencies->unmet($registry->find('Addon'));

    return $problems === [] ? true : 'reported: ' . implode(' ', $problems);
});

check('a disabled requirement is reported', static function () use ($build) {
    [$registry, $state] = $build('disabled');
    $state->disable('Shop');

    $problems = (new Dependencies($registry))->unmet($registry->find('Addon'));

    if ($problems === []) {
        return 'a disabled dependency was treated as met';
    }

    return str_contains($problems[0], 'disabled')
        ? true
        : "the message does not say it is disabled: {$problems[0]}";
});

check('a missing requirement is reported', static function () use ($temp, $writeExtension) {
    $root = $temp . '/deps-missing/extensions';

    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return 'could not create the scratch directory';
    }

    $writeExtension($root, 'Addon', '1.0.0', ['Shop' => '*']);

    $registry = new Registry($root, new State($temp . '/deps-missing-state.json'));
    $problems = (new Dependencies($registry))->unmet($registry->find('Addon'));

    if ($problems === []) {
        return 'a missing dependency was treated as met';
    }

    return str_contains($problems[0], 'not installed')
        ? true
        : "the message does not say it is missing: {$problems[0]}";
});

check('a version that is too old is reported', static function () use ($temp, $writeExtension) {
    $root = $temp . '/deps-old/extensions';

    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return 'could not create the scratch directory';
    }

    $writeExtension($root, 'Shop', '1.0.0');
    $writeExtension($root, 'Addon', '1.0.0', ['Shop' => '>=2.0.0']);

    $registry = new Registry($root, new State($temp . '/deps-old-state.json'));
    $problems = (new Dependencies($registry))->unmet($registry->find('Addon'));

    return $problems !== [] && str_contains($problems[0], '1.0.0')
        ? true
        : 'an old dependency was accepted, or the message does not name the version';
});

check('dependents are found, and only the enabled ones', static function () use ($build) {
    [$registry, $state] = $build('dependents');
    $dependencies = new Dependencies($registry);

    if ($dependencies->dependents('Shop') !== ['Addon']) {
        return 'the addon was not listed as depending on the shop';
    }

    if ($dependencies->dependents('Loner') !== []) {
        return 'something was reported as depending on an extension nothing uses';
    }

    $state->disable('Addon');

    return $dependencies->dependents('Shop') === []
        ? true
        : 'a disabled dependent still blocks the extension below it';
});

check('an extension cannot require itself', static function () use ($temp, $writeExtension) {
    $root = $temp . '/deps-self/extensions';

    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return 'could not create the scratch directory';
    }

    // A typo that would otherwise make the extension permanently
    // uninstallable, since it can never be enabled before itself.
    $writeExtension($root, 'Addon', '1.0.0', ['Addon' => '*']);

    $registry = new Registry($root, new State($temp . '/deps-self-state.json'));

    return $registry->find('Addon')?->requires === []
        ? true
        : 'an extension was allowed to require itself';
});

check('a malformed constraint becomes "any"', static function () use ($temp) {
    $root = $temp . '/deps-junk/extensions';
    $dir = $root . '/Addon';

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 'could not create the scratch directory';
    }

    // An operator cannot fix somebody else's manifest, so an unreadable
    // constraint must not be the reason an install is refused.
    file_put_contents($dir . '/extension.json', json_encode([
        'name' => 'Addon',
        'version' => '1.0.0',
        'entry' => 'Extensions\\Addon\\Extension',
        'requires' => ['extensions' => ['Shop' => ['not', 'a', 'constraint']]],
    ]));

    $registry = new Registry($root, new State($temp . '/deps-junk-state.json'));

    return ($registry->find('Addon')?->requires['Shop'] ?? '') === '*'
        ? true
        : 'a malformed constraint was kept as written';
});

check('requires that is not an object is ignored', static function () use ($temp) {
    $root = $temp . '/deps-scalar/extensions';
    $dir = $root . '/Addon';

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 'could not create the scratch directory';
    }

    file_put_contents($dir . '/extension.json', json_encode([
        'name' => 'Addon',
        'version' => '1.0.0',
        'entry' => 'Extensions\\Addon\\Extension',
        'requires' => 'php >= 8.2',
    ]));

    $registry = new Registry($root, new State($temp . '/deps-scalar-state.json'));

    return $registry->find('Addon') !== null && $registry->find('Addon')->requires === []
        ? true
        : 'a scalar requires broke the manifest instead of being ignored';
});

check('a hand-built manifest keeps its requirements', static function () {
    $manifest = new Manifest(
        slug: 'Addon',
        name: 'Addon',
        version: '1.0.0',
        description: '',
        entry: 'Extensions\\Addon\\Extension',
        path: '/nowhere',
        requires: ['Shop' => '^1.0']
    );

    return $manifest->requiresExtension('Shop') && !$manifest->requiresExtension('Other')
        ? true
        : 'requiresExtension() disagreed with the map it was built from';
});
