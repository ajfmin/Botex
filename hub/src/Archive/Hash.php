<?php

namespace Botex\Archive;

/**
 * File and tree hashing, the basis of both integrity and dirty detection.
 *
 * One algorithm, in one place: a package pins sha256 per file, the
 * inventory records sha256 per installed core file, and "did the operator
 * edit this" is the same question as "did the payload change in transit".
 * Both answers have to be computed identically or an update would compare
 * hashes that were never comparable.
 *
 * Line endings are deliberately NOT normalised. A file is what is on disk,
 * byte for byte; normalising would make a CRLF checkout look clean against
 * an LF package and hide a real difference.
 */
class Hash
{
    public const ALGORITHM = 'sha256';

    public static function string(string $contents): string
    {
        return hash(self::ALGORITHM, $contents);
    }

    /** @throws ArchiveException */
    public static function file(string $path): string
    {
        if (!is_file($path)) {
            throw new ArchiveException("Cannot hash missing file {$path}");
        }

        $hash = hash_file(self::ALGORITHM, $path);

        if ($hash === false) {
            throw new ArchiveException("Could not hash {$path}");
        }

        return $hash;
    }

    /** The hash, or null for a file that is not there. */
    public static function fileOrNull(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = hash_file(self::ALGORITHM, $path);

        return $hash === false ? null : $hash;
    }

    /**
     * Hashes every file under a directory.
     *
     * @param  string        $directory root to walk
     * @param  callable|null $filter    fn(string $relative): bool, false to skip
     * @return array<string,string> relative path => hash, sorted by path
     *
     * @throws ArchiveException
     */
    public static function directory(string $directory, ?callable $filter = null): array
    {
        $root = realpath($directory);

        if ($root === false || !is_dir($root)) {
            throw new ArchiveException("Not a directory: {$directory}");
        }

        $hashes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

            if ($filter !== null && !$filter($relative)) {
                continue;
            }

            $hashes[$relative] = self::file($item->getPathname());
        }

        // Sorted so a tree hash over this map is reproducible; directory
        // iteration order is filesystem dependent.
        ksort($hashes);

        return $hashes;
    }

    /**
     * One hash standing for a whole set of files.
     *
     * Both the path and the content hash go into the digest, so renaming a
     * file changes the tree hash even though its bytes did not -- a rename
     * is a change to the tree.
     *
     * @param array<string,string> $hashes path => file hash
     */
    public static function tree(array $hashes): string
    {
        ksort($hashes);

        $context = hash_init(self::ALGORITHM);

        foreach ($hashes as $path => $hash) {
            // NUL-separated: neither a path nor a hex hash can contain one,
            // so no pair of different maps can produce the same stream.
            hash_update($context, $path . "\0" . $hash . "\0");
        }

        return hash_final($context);
    }

    /**
     * Compares two hash maps.
     *
     * @param  array<string,string> $expected what should be there
     * @param  array<string,string> $actual   what is there
     * @return array{added:array<string>,removed:array<string>,changed:array<string>}
     */
    public static function diff(array $expected, array $actual): array
    {
        $changed = [];

        foreach ($expected as $path => $hash) {
            if (isset($actual[$path]) && !hash_equals($hash, $actual[$path])) {
                $changed[] = $path;
            }
        }

        sort($changed);

        $added = array_values(array_diff(array_keys($actual), array_keys($expected)));
        $removed = array_values(array_diff(array_keys($expected), array_keys($actual)));

        sort($added);
        sort($removed);

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /** Constant-time compare, for anything an attacker could probe. */
    public static function matches(string $expected, string $actual): bool
    {
        return hash_equals(strtolower($expected), strtolower($actual));
    }
}
