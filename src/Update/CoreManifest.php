<?php

namespace Botex\Update;

use Botex\Botex;
use Botex\Support\Config;

/**
 * The list of files this core release actually ships.
 *
 * It exists to answer one question the updater cannot otherwise answer on
 * a fresh install: *which of the files under src/ are ours?* Directory
 * location used to be the answer, and that is what made a custom command
 * in src/Bot/Command/ indistinguishable from a shipped one -- adopted as
 * core, then deleted by the next release that did not ship it.
 *
 * Shipped inside the package and committed to the repository, so it is
 * available offline, which is exactly when `core:adopt` is run. Paths
 * only, no hashes: hashes belong in the inventory, which records what is
 * on *this* disk, and a second hash list in version control would conflict
 * on every commit for no benefit.
 *
 * Written by `core:manifest` from a clean checkout, and rewritten from the
 * package's own file list after every update, so it cannot drift from what
 * was installed.
 */
class CoreManifest
{
    public const FILE = 'core.json';

    private string $root;

    private string $file;

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct(Config $config)
    {
        $this->root = rtrim((string) $config->get('paths.root', dirname(__DIR__, 2)), '/\\');
        $this->file = $this->root . '/' . self::FILE;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function path(): string
    {
        return $this->file;
    }

    /** The core version this list was generated for. */
    public function version(): ?string
    {
        $version = $this->read()['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Every path this release ships, relative to the project root.
     *
     * Filtered through the same gate a package is: a manifest that has
     * been edited to claim config/ or .env must not widen what the updater
     * considers core-owned.
     *
     * @return array<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ((array) ($this->read()['files'] ?? []) as $path) {
            if (is_string($path) && Inventory::isTracked($path)) {
                $paths[] = str_replace('\\', '/', $path);
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /** Whether this release ships a given path. */
    public function ships(string $path): bool
    {
        return in_array(str_replace('\\', '/', $path), $this->paths(), true);
    }

    /**
     * Replaces the list.
     *
     * @param  array<string> $paths
     * @return int           paths written
     */
    public function write(array $paths, ?string $version = null): int
    {
        // Always including itself, whatever the caller passed. The list is
        // shipped with the release like any other file, and a list that
        // leaves itself out declares itself user-owned -- which would make
        // the next release, which does ship it, collide with it and block
        // every update.
        $clean = [self::FILE => true];

        foreach ($paths as $path) {
            $path = str_replace('\\', '/', (string) $path);

            if (Inventory::isTracked($path)) {
                $clean[$path] = true;
            }
        }

        $clean = array_keys($clean);
        sort($clean);

        $json = json_encode([
            'version' => $version ?? Botex::VERSION,
            'generated_at' => gmdate('c'),
            'files' => $clean,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new UpdateException('Could not encode the core manifest.');
        }

        if (@file_put_contents($this->file, $json . "\n", LOCK_EX) === false) {
            throw new UpdateException("Could not write {$this->file}");
        }

        $this->data = null;

        return count($clean);
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->file)) {
            return $this->data = [];
        }

        $decoded = json_decode((string) @file_get_contents($this->file), true);

        return $this->data = is_array($decoded) ? $decoded : [];
    }
}
