<?php

namespace Hub;

use Botex\Archive\ArchiveException;
use Botex\Archive\Manifest;
use Botex\Archive\Package;
use Botex\Archive\Version;

/**
 * Turns a folder into a published release.
 *
 * Two shapes go in: an extension folder (read its extension.json) or a
 * Botex checkout (ship the tracked core directories). Both come out as a
 * verified, optionally signed .botex in the store, and the index is rebuilt.
 *
 * The package is re-opened and verified after building, before it is
 * written. That looks redundant -- it was just built from known files -- but
 * it means a bug in the writer or a bad filter is caught here, by the
 * publisher, rather than by every bot that downloads it.
 */
class Publisher
{
    /**
     * Names never included in a package.
     *
     * Local state, editor droppings, and -- importantly -- the two files
     * that hold an operator's own configuration. A published extension must
     * not carry someone's .env or a settings override, and the core tree
     * must not ship config/.
     */
    private const EXCLUDE = [
        '.git', '.gitignore', '.gitattributes', '.github',
        '.env', '.env.example', '.env.local',
        'node_modules', 'vendor', '.idea', '.vscode',
        '.DS_Store', 'Thumbs.db', 'desktop.ini',
        'storage', 'composer.lock',
    ];

    public function __construct(
        private Config $config,
        private Store $store,
        private Catalog $catalog
    ) {
    }

    /**
     * Publishes an extension folder.
     *
     * @throws ArchiveException
     */
    public function extension(
        string $directory,
        ?string $channel = null,
        ?string $version = null,
        string $changelog = '',
        bool $overwrite = false
    ): Manifest {
        $directory = $this->assertDirectory($directory);
        $slug = basename($directory);

        $file = $directory . '/extension.json';

        if (!is_file($file)) {
            throw new ArchiveException(
                "{$slug} has no extension.json, so it is not an extension folder."
            );
        }

        $meta = json_decode((string) file_get_contents($file), true);

        if (!is_array($meta)) {
            throw new ArchiveException("{$slug}: extension.json is not valid JSON.");
        }

        foreach (['name', 'version', 'entry'] as $key) {
            if (empty($meta[$key]) || !is_string($meta[$key])) {
                throw new ArchiveException("{$slug}: extension.json needs a '{$key}'.");
            }
        }

        $this->assertSlugMatchesEntry($slug, $meta['entry']);

        $bytes = Package::build(
            directory: $directory,
            type: Manifest::TYPE_EXTENSION,
            slug: $slug,
            name: $meta['name'],
            version: $version ?? $meta['version'],
            description: is_string($meta['description'] ?? null) ? $meta['description'] : '',
            requires: $this->requires($meta),
            commands: [],
            changelog: $changelog,
            readme: $this->readme($directory),
            filter: [$this, 'includes'],
            extra: $this->extra($meta)
        );

        return $this->store($bytes, $channel, $overwrite);
    }

    /**
     * Publishes a core release from a Botex checkout.
     *
     * Only the directories a core update is allowed to write are included,
     * which is what makes an update unable to touch config/ or storage/ --
     * they are never in the package to begin with.
     *
     * @throws ArchiveException
     */
    public function core(
        string $directory,
        ?string $channel = null,
        ?string $version = null,
        string $changelog = '',
        bool $overwrite = false
    ): Manifest {
        $directory = $this->assertDirectory($directory);

        $versionFile = $directory . '/src/Botex.php';

        if (!is_file($versionFile)) {
            throw new ArchiveException(
                "{$directory} does not look like a Botex checkout: src/Botex.php is missing."
            );
        }

        $detected = $this->versionIn($versionFile);
        $publishing = $version ?? $detected;

        if ($version !== null && $detected !== $version) {
            throw new ArchiveException(
                "src/Botex.php says {$detected} but you asked to publish {$publishing}. "
                    . 'Bump the constant first, so an installed bot reports the version it is running.'
            );
        }

        // Staged, because a core package holds several top-level
        // directories and Package::build packs exactly one.
        $staging = sys_get_temp_dir() . '/botex-publish-' . bin2hex(random_bytes(4));

        try {
            $this->stageCore($directory, $staging);

            $bytes = Package::build(
                directory: $staging,
                type: Manifest::TYPE_CORE,
                slug: 'core',
                name: 'Botex',
                version: $publishing,
                description: 'The Botex core.',
                requires: ['php' => '>=8.2'],
                commands: [],
                changelog: $changelog,
                readme: $this->readme($directory),
                filter: [$this, 'includes']
            );
        } finally {
            $this->deleteDirectory($staging);
        }

        return $this->store($bytes, $channel, $overwrite);
    }

    /**
     * Publishes an already-built .botex file.
     *
     * @throws ArchiveException
     */
    public function file(string $file, ?string $channel = null, bool $overwrite = false): Manifest
    {
        if (!is_file($file)) {
            throw new ArchiveException("No package at {$file}");
        }

        $bytes = (string) file_get_contents($file);

        return $this->store($bytes, $channel, $overwrite);
    }

    /**
     * Verifies, signs and stores package bytes.
     *
     * @throws ArchiveException
     */
    private function store(string $bytes, ?string $channel, bool $overwrite): Manifest
    {
        // Verifies the manifest against every payload file.
        $package = Package::fromString($bytes);
        $manifest = $package->manifest;

        $channel = $channel === null
            ? $this->config->defaultChannel()
            : $this->assertChannel($channel);

        if (!$overwrite && $this->store->has($manifest->slug, $manifest->version)) {
            throw new ArchiveException(
                "{$manifest->slug} {$manifest->version} is already published. "
                    . 'Bump the version, or pass --overwrite to replace it.'
            );
        }

        $this->store->put($manifest, $bytes, $channel, $this->sign($bytes));
        $this->catalog->rebuild();

        return $manifest;
    }

    /**
     * Signs the package, when a signing key is configured.
     *
     * @throws ArchiveException
     */
    private function sign(string $bytes): string
    {
        if (!$this->config->signs()) {
            return '';
        }

        if (!extension_loaded('openssl')) {
            throw new ArchiveException(
                'A signing key is configured but this PHP has no openssl.'
            );
        }

        $keyFile = $this->config->signingKey();

        if (!is_file($keyFile) || !is_readable($keyFile)) {
            throw new ArchiveException("The signing key {$keyFile} is not readable.");
        }

        $key = openssl_pkey_get_private(
            (string) file_get_contents($keyFile),
            $this->config->signingKeyPassphrase()
        );

        if ($key === false) {
            throw new ArchiveException('Could not load the signing key. Wrong passphrase?');
        }

        $signature = '';

        if (!openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ArchiveException('Could not sign the package.');
        }

        return base64_encode($signature);
    }

    /** Removes a release and reindexes. */
    public function unpublish(string $slug, string $version): bool
    {
        $removed = $this->store->forget($slug, $version);

        if ($removed) {
            $this->catalog->rebuild();
        }

        return $removed;
    }

    /**
     * Whether a path belongs in a package.
     *
     * Public because Package::build takes it as a callable.
     */
    public function includes(string $relative): bool
    {
        $path = str_replace('\\', '/', $relative);

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, self::EXCLUDE, true)) {
                return false;
            }
        }

        // Never ship a built package or a log.
        return !str_ends_with($path, '.botex')
            && !str_ends_with($path, '.log')
            && !str_ends_with($path, '.tmp');
    }

    /**
     * Copies the tracked core directories into a staging folder.
     *
     * The list mirrors Botex\Update\Inventory::TRACKED, and the two must
     * agree: a file the bot will not write is a file there is no point
     * shipping.
     */
    private function stageCore(string $source, string $staging): void
    {
        if (!@mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new ArchiveException("Could not create {$staging}");
        }

        $directories = ['src', 'bootstrap', 'public', 'bin', 'docs'];
        $files = ['composer.json'];
        $copied = 0;

        foreach ($directories as $directory) {
            $from = $source . '/' . $directory;

            if (!is_dir($from)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $relative = $directory . '/' . str_replace(
                    '\\',
                    '/',
                    substr($item->getPathname(), strlen($from) + 1)
                );

                if (!$this->includes($relative)) {
                    continue;
                }

                $target = $staging . '/' . $relative;

                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
                    throw new ArchiveException('Could not stage ' . $relative);
                }

                if (!@copy($item->getPathname(), $target)) {
                    throw new ArchiveException('Could not stage ' . $relative);
                }

                $copied++;
            }
        }

        foreach ($files as $file) {
            if (is_file($source . '/' . $file) && @copy($source . '/' . $file, $staging . '/' . $file)) {
                $copied++;
            }
        }

        if ($copied === 0) {
            throw new ArchiveException("Nothing to publish from {$source}");
        }
    }

    /**
     * Refuses a folder whose name does not match the entry namespace.
     *
     * The slug is the install directory, and the bot's autoloader maps
     * Extensions\<Slug>\... onto extensions/<Slug>/.... So publishing Clock
     * from a folder called clock-1.3.0 would produce a package that installs
     * into extensions/clock-1.3.0/ where its own entry class can never be
     * found. Cheap to check here, confusing to diagnose on the bot.
     *
     * @throws ArchiveException
     */
    private function assertSlugMatchesEntry(string $slug, string $entry): void
    {
        $entry = ltrim(str_replace('/', '\\', $entry), '\\');
        $prefix = 'Extensions\\';

        if (!str_starts_with($entry, $prefix)) {
            throw new ArchiveException(
                "{$slug}: entry '{$entry}' must start with {$prefix}, "
                    . 'because that is the namespace the bot loads extensions from.'
            );
        }

        $expected = explode('\\', substr($entry, strlen($prefix)))[0];

        if ($expected === '' || $expected === $slug) {
            return;
        }

        throw new ArchiveException(
            "The folder is named '{$slug}' but entry '{$entry}' says the extension is '{$expected}'. "
                . "Rename the folder to '{$expected}' before publishing: the folder name becomes the "
                . 'install directory, and a mismatch leaves the entry class unloadable.'
        );
    }

    /** @param array<string,mixed> $meta */
    private function requires(array $meta): array
    {
        $requires = [];

        foreach ((array) ($meta['requires'] ?? []) as $key => $constraint) {
            if (in_array($key, ['botex', 'php'], true) && is_string($constraint)) {
                $requires[$key] = $constraint;
            }
        }

        return $requires;
    }

    /**
     * Publisher metadata an extension may declare, passed through untouched.
     *
     * @param array<string,mixed> $meta
     */
    private function extra(array $meta): array
    {
        $extra = [];

        foreach (['author', 'homepage', 'license'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                $extra[$key] = $meta[$key];
            }
        }

        $keywords = array_values(array_filter((array) ($meta['keywords'] ?? []), 'is_string'));

        if ($keywords !== []) {
            $extra['keywords'] = array_slice($keywords, 0, 10);
        }

        return $extra;
    }

    /** A README from the folder, for the package page. */
    private function readme(string $directory): string
    {
        foreach (['README.md', 'readme.md', 'README.txt'] as $name) {
            $file = $directory . '/' . $name;

            if (is_file($file)) {
                // Capped: this ends up in index.json, which is read on every
                // page view, and a novel-length readme would bloat it.
                return substr((string) file_get_contents($file), 0, 20000);
            }
        }

        return '';
    }

    private function versionIn(string $file): string
    {
        $contents = (string) file_get_contents($file);

        if (preg_match("/VERSION\s*=\s*'([^']+)'/", $contents, $matches) !== 1) {
            throw new ArchiveException("Could not read the version from {$file}");
        }

        if (!Version::isValid($matches[1])) {
            throw new ArchiveException("{$file} declares an invalid version '{$matches[1]}'.");
        }

        return $matches[1];
    }

    private function assertDirectory(string $directory): string
    {
        $resolved = realpath($directory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new ArchiveException("Not a directory: {$directory}");
        }

        return str_replace('\\', '/', rtrim($resolved, '/\\'));
    }

    private function assertChannel(string $channel): string
    {
        if (!in_array($channel, $this->config->channels(), true)) {
            throw new ArchiveException(
                "Unknown channel '{$channel}'. Configured: " . implode(', ', $this->config->channels())
            );
        }

        return $channel;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
