<?php

/**
 * The package format: manifest, payload, and the checks tying them together.
 *
 * A package is the only thing a bot ever unpacks over its own code, so the
 * checks that matter here are the ones that refuse a bad one.
 */

use Botex\Archive\Manifest;
use Botex\Archive\Package;
use Botex\Archive\Reader;
use Botex\Archive\Zip;

group('Package');

/** Builds a small extension package in $temp and returns its bytes. */
$build = static function (string $temp, string $slug = 'Sample', string $version = '1.0.0'): string {
    $source = $temp . '/src-' . bin2hex(random_bytes(3));

    @mkdir($source . '/Command', 0775, true);

    file_put_contents($source . '/extension.json', json_encode([
        'name' => 'Sample Extension',
        'version' => $version,
        'entry' => 'Extensions\\' . $slug . '\\Extension',
        'description' => 'For the selftest.',
    ]));

    file_put_contents($source . '/Extension.php', "<?php\n\nreturn 'sample';\n");
    file_put_contents($source . '/Command/Ping.php', "<?php\n\nreturn 'ping';\n");

    return Package::build(
        directory: $source,
        type: Manifest::TYPE_EXTENSION,
        slug: $slug,
        name: 'Sample Extension',
        version: $version,
        description: 'For the selftest.',
        requires: ['php' => '>=8.2'],
        commands: [],
        changelog: 'First.',
        readme: '# Sample'
    );
};

check('a built package re-opens and verifies', static function () use ($build, $temp) {
    $package = Package::fromString($build($temp));

    return $package->slug() === 'Sample'
        && $package->version() === '1.0.0'
        && $package->manifest->type === Manifest::TYPE_EXTENSION
        ? true
        : 'the manifest does not describe what was built';
});

check('every payload file is listed in the manifest with its hash', static function () use ($build, $temp) {
    $package = Package::fromString($build($temp));
    $files = $package->files();

    foreach (['extension.json', 'Extension.php', 'Command/Ping.php'] as $expected) {
        if (!in_array($expected, $files, true)) {
            return "'{$expected}' is missing from the manifest";
        }

        if (($package->manifest->files[$expected] ?? '') === '') {
            return "'{$expected}' has no hash";
        }
    }

    return count($files) === 3 ? true : 'the manifest lists ' . count($files) . ' files, expected 3';
});

refuses('a package whose payload was edited after signing the manifest', static function () use ($build, $temp) {
    $bytes = $build($temp);
    $reader = Reader::fromString($bytes);

    // Repack with one file changed, leaving the manifest as it was: the
    // per-file hash comparison is what has to catch this.
    $writer = Zip::writer();

    foreach ($reader->names() as $name) {
        $writer->add(
            $name,
            $name === Manifest::PAYLOAD . '/Extension.php'
                ? "<?php\n\nreturn 'tampered';\n"
                : $reader->get($name)
        );
    }

    Package::fromString($writer->toString());
});

refuses('a package carrying a payload file the manifest never mentions', static function () use ($build, $temp) {
    $reader = Reader::fromString($build($temp));

    $writer = Zip::writer();

    foreach ($reader->names() as $name) {
        $writer->add($name, $reader->get($name));
    }

    $writer->add(Manifest::PAYLOAD . '/Backdoor.php', "<?php\n\n// not in the manifest\n");

    Package::fromString($writer->toString());
});

refuses('an archive with no manifest at all', static function () {
    $writer = Zip::writer();
    $writer->add(Manifest::PAYLOAD . '/Extension.php', '<?php');

    Package::fromString($writer->toString());
});

refuses('a manifest that is not valid JSON', static function () {
    $writer = Zip::writer();
    $writer->add(Manifest::FILE, '{not json');
    $writer->add(Manifest::PAYLOAD . '/Extension.php', '<?php');

    Package::fromString($writer->toString());
});

refuses('a manifest with no slug', static function () {
    Manifest::fromArray([
        'type' => Manifest::TYPE_EXTENSION,
        'version' => '1.0.0',
        'name' => 'Nameless',
        'files' => [],
    ]);
});

refuses('a manifest with an invalid version', static function () {
    Manifest::fromArray([
        'type' => Manifest::TYPE_EXTENSION,
        'slug' => 'Sample',
        'version' => 'not-a-version',
        'name' => 'Sample',
        'files' => [],
    ]);
});

refuses('a manifest listing a traversing file path', static function () {
    Manifest::fromArray([
        'type' => Manifest::TYPE_EXTENSION,
        'slug' => 'Sample',
        'version' => '1.0.0',
        'name' => 'Sample',
        'files' => ['../../../etc/passwd' => str_repeat('a', 64)],
    ]);
});

check('a manifest survives a JSON round trip unchanged', static function () use ($build, $temp) {
    $original = Package::fromString($build($temp))->manifest;
    $again = Manifest::fromJson($original->toJson());

    return $again->slug === $original->slug
        && $again->version === $original->version
        && $again->files === $original->files
        ? true
        : 'the manifest changed when re-read from its own JSON';
});

check('the same input produces the same package hash', static function () use ($temp) {
    $source = $temp . '/stable';

    @mkdir($source, 0775, true);
    file_put_contents($source . '/extension.json', '{"name":"S","version":"1.0.0","entry":"Extensions\\\\S\\\\Extension"}');
    file_put_contents($source . '/Extension.php', '<?php');

    $make = static fn (): array => Package::fromString(Package::build(
        directory: $source,
        type: Manifest::TYPE_EXTENSION,
        slug: 'S',
        name: 'S',
        version: '1.0.0',
        description: '',
        requires: [],
        commands: [],
        changelog: '',
        readme: ''
    ))->manifest->files;

    return $make() === $make()
        ? true
        : 'the file inventory differs between two builds of identical input';
});
