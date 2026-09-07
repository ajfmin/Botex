<?php

namespace Botex\Update;

use Botex\Support\Config;

/**
 * Copies of whatever an update is about to overwrite.
 *
 * Taken before any write, into storage/backups/<utc timestamp>/, and used
 * by `core:rollback`. The atomic swap in the installers means a *failed*
 * update restores itself; this is for the other case -- an update that
 * succeeded and turned out to be wrong.
 *
 * Only files that were actually replaced or deleted are copied, so a backup
 * is small and restoring one cannot resurrect a file the update legitimately
 * left alone.
 */
class Backup
{
    public const DIRECTORY = 'backups';

    /** Backups older than this are swept when a new one is taken. */
    public const KEEP = 10;

    private string $root;

    private string $path;

    public function __construct(Config $config)
    {
        $this->root = rtrim((string) $config->get('paths.root', dirname(__DIR__, 2)), '/\\');

        $this->path = rtrim(
            (string) $config->get('paths.storage', $this->root . '/storage'),
            '/\\'
        ) . '/' . self::DIRECTORY;
    }

    /**
     * Starts a backup and returns its label.
     *
     * The label is a UTC timestamp with a short random suffix, so two
     * updates in the same second cannot collide.
     */
    public function begin(string $reason = ''): string
    {
        $label = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        $directory = $this->path . '/' . $label;

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException("Could not create the backup directory {$directory}");
        }

        @file_put_contents($directory . '/backup.json', (string) json_encode([
            'label' => $label,
            'reason' => $reason,
            'created_at' => gmdate('c'),
            'botex' => \Botex\Botex::VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $label;
    }

    /**
     * Copies one file into a backup, keeping its relative path.
     *
     * A path that does not exist is skipped rather than failing: an update
     * that adds a file has nothing to back up there.
     */
    public function keep(string $label, string $relative): bool
    {
        $source = $this->root . '/' . ltrim($relative, '/');

        if (!is_file($source)) {
            return false;
        }

        $target = $this->path . '/' . $label . '/files/' . ltrim($relative, '/');
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException("Could not create {$directory}");
        }

        if (!@copy($source, $target)) {
            throw new UpdateException("Could not back up {$relative}");
        }

        return true;
    }

    /**
     * Copies a whole directory into a backup.
     *
     * Used when an extension folder is replaced: the folder goes in whole,
     * since a replacement swaps all of it at once.
     */
    public function keepDirectory(string $label, string $relative): int
    {
        $source = $this->root . '/' . ltrim($relative, '/');

        if (!is_dir($source)) {
            return 0;
        }

        $kept = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $itemRelative = ltrim($relative, '/') . '/' . str_replace(
                '\\',
                '/',
                substr($item->getPathname(), strlen($source) + 1)
            );

            if ($this->keep($label, $itemRelative)) {
                $kept++;
            }
        }

        return $kept;
    }

    /**
     * Restores every file in a backup.
     *
     * @return array<string> paths restored
     */
    public function restore(string $label): array
    {
        $directory = $this->path . '/' . $this->label($label) . '/files';

        if (!is_dir($directory)) {
            throw new UpdateException("Backup '{$label}' has no files to restore.");
        }

        $restored = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
            $target = $this->root . '/' . $relative;
            $parent = dirname($target);

            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new UpdateException("Could not create {$parent}");
            }

            if (!@copy($item->getPathname(), $target)) {
                throw new UpdateException("Could not restore {$relative}");
            }

            $restored[] = $relative;
        }

        sort($restored);

        return $restored;
    }

    /**
     * Backups on disk, newest first.
     *
     * @return array<string, array<string,mixed>> label => metadata
     */
    public function all(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $backups = [];

        foreach ((array) scandir($this->path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($this->path . '/' . $entry)) {
                continue;
            }

            $meta = json_decode(
                (string) @file_get_contents($this->path . '/' . $entry . '/backup.json'),
                true
            );

            $backups[$entry] = is_array($meta) ? $meta : ['label' => $entry];
            $backups[$entry]['files'] = $this->countFiles($entry);
        }

        krsort($backups);

        return $backups;
    }

    public function latest(): ?string
    {
        $all = $this->all();

        return $all === [] ? null : (string) array_key_first($all);
    }

    public function exists(string $label): bool
    {
        return is_dir($this->path . '/' . $label);
    }

    /**
     * Resolves a label, defaulting to the newest.
     *
     * @throws UpdateException
     */
    public function label(?string $label): string
    {
        $resolved = $label ?? $this->latest();

        if ($resolved === null) {
            throw new UpdateException('There are no backups to roll back to.');
        }

        // Rejected before it can be joined onto a path: a label comes from
        // argv and this class turns it into a filesystem location.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $resolved) !== 1) {
            throw new UpdateException("'{$resolved}' is not a valid backup label.");
        }

        if (!$this->exists($resolved)) {
            throw new UpdateException("There is no backup '{$resolved}'.");
        }

        return $resolved;
    }

    /** Deletes all but the newest KEEP backups. */
    public function prune(int $keep = self::KEEP): int
    {
        $labels = array_keys($this->all());
        $stale = array_slice($labels, max(0, $keep));
        $removed = 0;

        foreach ($stale as $label) {
            if ($this->delete((string) $label)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function delete(string $label): bool
    {
        $directory = $this->path . '/' . $label;
        $root = realpath($directory);
        $parent = realpath($this->path);

        // Never delete outside the backups folder, whatever the label said.
        if ($root === false || $parent === false || dirname($root) !== $parent) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return @rmdir($root);
    }

    public function path(string $label = ''): string
    {
        return $label === '' ? $this->path : $this->path . '/' . $label;
    }

    private function countFiles(string $label): int
    {
        $directory = $this->path . '/' . $label . '/files';

        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
