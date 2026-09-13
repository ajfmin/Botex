<?php

namespace Botex\Update;

use Botex\Archive\Hash;
use Botex\Botex;
use Botex\Support\Config;

/**
 * What the core looked like when it was installed.
 *
 * This is the file that makes "update without resetting my changes"
 * answerable at all. Without a record of the bytes that were shipped, a
 * local edit and an upstream change are indistinguishable, and the only
 * safe options left are "never update" or "always overwrite".
 *
 * Kept in storage/ -- which no update touches -- as a map of path => sha256
 * for every core file, plus the version they came from.
 *
 * The absence of a record is never read as "clean". A fresh clone has no
 * inventory, so everything would look modified; `core:adopt` is the
 * explicit way to say "what is on disk now is my baseline", and until it
 * has been run the updater says so rather than guessing.
 *
 * **What it records is what makes a file core-owned.** A path under src/
 * that was never recorded belongs to the operator -- a command they wrote
 * next to the shipped ones -- and the updater neither replaces nor deletes
 * it. Directory location decides only where a core package is *allowed* to
 * write (see isTracked()); this record decides what it actually owns.
 */
class Inventory
{
    public const FILE = 'inventory.json';

    /**
     * Directories a core package may replace, relative to the project root.
     *
     * Everything outside this list is the operator's, and no core update
     * writes there. `extensions/` is absent on purpose: extensions are
     * updated one at a time, by their own package.
     */
    public const TRACKED = ['src', 'bootstrap', 'public', 'bin', 'docs'];

    /**
     * Files a core package may replace at the project root.
     *
     * config/ and .env are absent deliberately: those are the operator's
     * configuration, and replacing them is exactly the "reset my changes"
     * failure this whole subsystem exists to prevent. composer.json is
     * tracked because a core release can add a dependency, and core.json
     * because it is the release's own list of what it ships.
     */
    public const TRACKED_FILES = ['composer.json', CoreManifest::FILE];

    private string $root;

    private string $file;

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct(Config $config)
    {
        $this->root = rtrim(
            (string) $config->get('paths.root', dirname(__DIR__, 2)),
            '/\\'
        );

        $this->file = rtrim(
            (string) $config->get('paths.storage', $this->root . '/storage'),
            '/\\'
        ) . '/' . self::FILE;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** The core version this inventory was recorded for. */
    public function version(): ?string
    {
        $version = $this->read()['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    public function recordedAt(): ?string
    {
        $at = $this->read()['recorded_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * Recorded hashes.
     *
     * @return array<string,string> path => sha256
     */
    public function hashes(): array
    {
        $files = $this->read()['files'] ?? [];

        return is_array($files) ? array_map('strval', $files) : [];
    }

    /**
     * Hashes of what is actually on disk now.
     *
     * @return array<string,string> path => sha256
     */
    public function current(): array
    {
        $hashes = [];

        foreach (self::TRACKED as $directory) {
            $path = $this->root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            foreach (Hash::directory($path, [self::class, 'tracks']) as $relative => $hash) {
                $hashes[$directory . '/' . $relative] = $hash;
            }
        }

        foreach (self::TRACKED_FILES as $file) {
            $hash = Hash::fileOrNull($this->root . '/' . $file);

            if ($hash !== null) {
                $hashes[$file] = $hash;
            }
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * Whether a tracked path is part of the core surface.
     *
     * Anything generated, cached or platform-specific is excluded: it is
     * not shipped, so recording it would report a difference on every run.
     */
    public static function tracks(string $relative): bool
    {
        $path = str_replace('\\', '/', $relative);

        // Editor and OS litter, and anything a tool dropped in a src folder.
        foreach (['.DS_Store', 'Thumbs.db', 'desktop.ini'] as $noise) {
            if (basename($path) === $noise) {
                return false;
            }
        }

        foreach (['.git/', 'node_modules/', 'vendor/'] as $skip) {
            if (str_starts_with($path, $skip) || str_contains($path, '/' . $skip)) {
                return false;
            }
        }

        // Logs and caches, in case storage is ever pointed inside a tracked
        // directory.
        return !str_ends_with($path, '.log') && !str_ends_with($path, '.cache');
    }

    /**
     * Core files the operator has edited, added or deleted.
     *
     * @return array{changed:array<string>,added:array<string>,removed:array<string>}
     */
    public function drift(): array
    {
        $diff = Hash::diff($this->hashes(), $this->current());

        return [
            'changed' => $diff['changed'],
            // An added file is not a conflict on its own -- a core update
            // will not touch a path it does not ship -- but it is reported
            // so `core:diff` shows the whole picture.
            'added' => $diff['added'],
            'removed' => $diff['removed'],
        ];
    }

    /**
     * Tracked files that differ from what was installed.
     *
     * These are what `core:update` refuses over, since replacing one would
     * discard an edit. A deleted file counts: the operator removed it
     * deliberately and putting it back is also a surprise.
     *
     * @return array<string>
     */
    public function dirty(): array
    {
        $drift = $this->drift();
        $dirty = array_merge($drift['changed'], $drift['removed']);

        sort($dirty);

        return $dirty;
    }

    public function isClean(): bool
    {
        return $this->dirty() === [];
    }

    /**
     * Whether drift can be judged at all.
     *
     * False means there is no baseline, so every answer about local changes
     * would be a guess. Callers must say so rather than assume clean.
     */
    public function isUsable(): bool
    {
        return $this->exists() && $this->hashes() !== [];
    }

    /**
     * Whether this record was narrowed to a release's own file list.
     *
     * False for one written by a core older than the ownership rule, which
     * hashed every file under the tracked directories -- including any the
     * operator had added. Such a record still measures edits correctly; it
     * just cannot be trusted to say what the core *owns*, so a deletion
     * planned from it is checked against the release manifest first.
     */
    public function isScoped(): bool
    {
        return ($this->read()['scoped'] ?? false) === true;
    }

    /**
     * Whether this path is one the core owns.
     *
     * The whole ownership question, in one line: it is ours if we recorded
     * shipping it. Everything else under a tracked directory is the
     * operator's, however core-looking the folder is.
     */
    public function owns(string $path): bool
    {
        return isset($this->hashes()[str_replace('\\', '/', $path)]);
    }

    /**
     * Files on disk inside the core surface that the core does not own.
     *
     * The operator's own: a command in src/Bot/Command/, a helper beside
     * it. Reported so they can be shown, never so they can be written to.
     *
     * @return array<string>
     */
    public function userOwned(): array
    {
        $mine = array_keys(array_diff_key($this->current(), $this->hashes()));

        sort($mine);

        return $mine;
    }

    /**
     * Records the tree as the baseline.
     *
     * @param  string|null        $version the core version now on disk
     * @param  array<string>|null $only    record just these paths -- the
     *                                     release's own file list -- so
     *                                     files the core never shipped are
     *                                     not adopted as core-owned
     * @return int                files recorded
     */
    public function record(?string $version = null, ?array $only = null): int
    {
        $hashes = $this->current();

        if ($only !== null) {
            $hashes = array_intersect_key($hashes, array_flip(array_map(
                static fn (string $path): string => str_replace('\\', '/', $path),
                $only
            )));
        }

        $this->write([
            'version' => $version ?? Botex::VERSION,
            'recorded_at' => gmdate('c'),
            // Whether this record was narrowed to the release's own
            // file list. Its absence means an older core wrote it, by
            // hashing the whole tree -- so it may name files the core
            // never shipped, and cannot be trusted to authorise a
            // deletion on its own.
            'scoped' => $only !== null,
            'tree' => Hash::tree($hashes),
            'files' => $hashes,
        ]);

        return count($hashes);
    }

    /**
     * Records a specific set of hashes, e.g. straight from a package that
     * was just installed.
     *
     * Cheaper than record() -- no rehashing of the tree -- and exact: what
     * is stored is what the package said it wrote.
     *
     * @param array<string,string> $hashes path => sha256
     */
    public function adopt(array $hashes, string $version): void
    {
        ksort($hashes);

        $this->write([
            'version' => $version,
            'recorded_at' => gmdate('c'),
            // Exact by construction: these hashes came from a
            // package, so they are that release and nothing else.
            'scoped' => true,
            'tree' => Hash::tree($hashes),
            'files' => $hashes,
        ]);
    }

    /**
     * Updates the record for individual paths, leaving the rest alone.
     *
     * Used after a partial write: only the files that were actually
     * replaced move to their new hashes, so an untouched local edit stays
     * recorded as it was rather than being silently blessed.
     *
     * @param array<string,string> $hashes path => sha256
     */
    public function merge(array $hashes, ?string $version = null): void
    {
        $data = $this->read();
        $files = $this->hashes();

        foreach ($hashes as $path => $hash) {
            $files[$path] = $hash;
        }

        ksort($files);

        $this->write([
            'version' => $version ?? ($data['version'] ?? Botex::VERSION),
            'recorded_at' => gmdate('c'),
            'tree' => Hash::tree($files),
            'files' => $files,
        ]);
    }

    /** Drops paths from the record, for files an update deleted. */
    public function forget(array $paths, ?string $version = null): void
    {
        $files = $this->hashes();

        foreach ($paths as $path) {
            unset($files[$path]);
        }

        $this->write([
            'version' => $version ?? ($this->read()['version'] ?? Botex::VERSION),
            'recorded_at' => gmdate('c'),
            'tree' => Hash::tree($files),
            'files' => $files,
        ]);
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

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException("Could not create {$directory}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new UpdateException('Could not encode the inventory.');
        }

        // Temp file plus rename: a half-written inventory would make every
        // file look modified, which blocks all future updates.
        $temporary = $this->file . '.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new UpdateException("Could not write {$this->file}");
        }

        if (is_file($this->file) && !@unlink($this->file)) {
            @unlink($temporary);

            throw new UpdateException("Could not replace {$this->file}");
        }

        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new UpdateException("Could not move the inventory into place.");
        }

        $this->data = $data;
    }

    /** Absolute path for a tracked relative path. */
    public function absolute(string $relative): string
    {
        return $this->root . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Whether a path is one a core package is allowed to write.
     *
     * The guard that keeps config/, .env and storage/ out of reach no
     * matter what a package's manifest lists.
     */
    public static function isTracked(string $relative): bool
    {
        $path = ltrim(str_replace('\\', '/', $relative), '/');

        // A traversing path is refused here rather than resolved, so this
        // method can be trusted on its own. Zip::path() already rejects
        // these when a package is parsed, but isTracked() is the last gate
        // before a write and is called with paths from an inventory file and
        // a plan as well -- it must not depend on an earlier caller having
        // checked. 'src/../../../etc/passwd' starts with 'src/' and would
        // otherwise be treated as a core file.
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        // An absolute path is never relative to the install root, and a
        // drive letter would make dirname()/copy() write outside it.
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        if (in_array($path, self::TRACKED_FILES, true)) {
            return true;
        }

        foreach (self::TRACKED as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return self::tracks(substr($path, strlen($directory) + 1));
            }
        }

        return false;
    }
}
