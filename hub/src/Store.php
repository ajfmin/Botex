<?php

namespace Hub;

use Botex\Archive\ArchiveException;
use Botex\Archive\Hash;
use Botex\Archive\Manifest;
use Botex\Archive\Package;
use Botex\Archive\Version;

/**
 * Packages on disk.
 *
 * Layout, one directory per version:
 *
 *   storage/packages/<slug>/<version>/package.botex
 *                                    /botex.json     the manifest, extracted
 *                                    /release.json    channel, hash, published_at
 *                                    /package.sig     detached signature, if signed
 *   storage/index.json                                generated catalog
 *   storage/downloads.json                            counters
 *
 * Files rather than a database because a package *is* a file, the metadata
 * is small, and the whole archive then moves with a copy of one folder.
 *
 * Every slug and version reaching this class is validated before it is
 * joined onto a path -- they arrive from a URL, so treating them as
 * trustworthy would be a traversal waiting to happen.
 */
class Store
{
    public const PACKAGE = 'package.botex';
    public const MANIFEST = 'botex.json';
    public const RELEASE = 'release.json';
    public const SIGNATURE = 'package.sig';

    private string $root;

    public function __construct(
        private Config $config
    ) {
        $this->root = $this->config->storage() . '/packages';
    }

    /** @throws ArchiveException on an unusable slug */
    public static function assertSlug(string $slug): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $slug) !== 1 || str_starts_with($slug, '.')) {
            throw new ArchiveException("Invalid package slug '{$slug}'.");
        }

        return $slug;
    }

    /** @throws ArchiveException */
    public static function assertVersion(string $version): string
    {
        if (!Version::isValid($version)) {
            throw new ArchiveException("Invalid version '{$version}'.");
        }

        // Valid semver cannot contain a separator, but this is the value
        // about to become a directory name, so it is checked as one too.
        if (preg_match('#[/\\\\]#', $version) === 1) {
            throw new ArchiveException("Invalid version '{$version}'.");
        }

        return $version;
    }

    /** @return array<string> every published slug, sorted */
    public function slugs(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $slugs = [];

        foreach ((array) scandir($this->root) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($this->root . '/' . $entry)) {
                continue;
            }

            $slugs[] = (string) $entry;
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * Published versions of a package, newest first.
     *
     * @return array<string>
     */
    public function versions(string $slug): array
    {
        $directory = $this->root . '/' . self::assertSlug($slug);

        if (!is_dir($directory)) {
            return [];
        }

        $versions = [];

        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($directory . '/' . $entry)) {
                continue;
            }

            if (Version::isValid((string) $entry)) {
                $versions[] = (string) $entry;
            }
        }

        return Version::sortDescending($versions);
    }

    /**
     * Versions on a given channel, newest first.
     *
     * @return array<string>
     */
    public function versionsOn(string $slug, string $channel): array
    {
        return array_values(array_filter(
            $this->versions($slug),
            fn (string $version): bool => $this->release($slug, $version)['channel'] === $channel
        ));
    }

    /**
     * The newest version on a channel, or null.
     *
     * Prereleases are skipped unless the channel is one that expects them,
     * which is decided by the publisher rather than guessed from the version
     * string -- a 1.0.0 published to `beta` is still a beta.
     */
    public function latest(string $slug, ?string $channel = null): ?string
    {
        $channel ??= $this->config->defaultChannel();
        $versions = $this->versionsOn($slug, $channel);

        return $versions === [] ? null : $versions[0];
    }

    public function has(string $slug, string $version): bool
    {
        return is_file($this->file($slug, $version, self::PACKAGE));
    }

    public function exists(string $slug): bool
    {
        return $this->versions($slug) !== [];
    }

    /** Absolute path to a file inside a release directory. */
    public function file(string $slug, string $version, string $name): string
    {
        return $this->directory($slug, $version) . '/' . $name;
    }

    public function directory(string $slug, string $version): string
    {
        return $this->root . '/' . self::assertSlug($slug) . '/' . self::assertVersion($version);
    }

    /**
     * The manifest of one release.
     *
     * @throws ArchiveException
     */
    public function manifest(string $slug, string $version): Manifest
    {
        $file = $this->file($slug, $version, self::MANIFEST);

        if (!is_file($file)) {
            throw new ArchiveException("No manifest for {$slug} {$version}.");
        }

        return Manifest::fromJson((string) file_get_contents($file));
    }

    /**
     * Release metadata: channel, hash, publication time.
     *
     * Defaults rather than a throw, so a hand-copied release directory
     * without one still lists instead of breaking the index.
     *
     * @return array{channel:string,sha256:string,published_at:string,size:int,signed:bool}
     */
    public function release(string $slug, string $version): array
    {
        $file = $this->file($slug, $version, self::RELEASE);
        $data = is_file($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;

        $data = is_array($data) ? $data : [];

        return [
            'channel' => (string) ($data['channel'] ?? $this->config->defaultChannel()),
            'sha256' => (string) ($data['sha256'] ?? ''),
            'published_at' => (string) ($data['published_at'] ?? ''),
            'size' => (int) ($data['size'] ?? 0),
            'signed' => (bool) ($data['signed'] ?? is_file($this->file($slug, $version, self::SIGNATURE))),
        ];
    }

    /**
     * Writes a release to disk.
     *
     * @param  string $bytes     the .botex file
     * @param  string $signature detached signature, or ''
     *
     * @throws ArchiveException
     */
    public function put(
        Manifest $manifest,
        string $bytes,
        string $channel,
        string $signature = ''
    ): string {
        $slug = self::assertSlug($manifest->slug);
        $version = self::assertVersion($manifest->version);
        $directory = $this->directory($slug, $version);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ArchiveException("Could not create {$directory}");
        }

        $this->write($directory . '/' . self::PACKAGE, $bytes);
        $this->write($directory . '/' . self::MANIFEST, $manifest->toJson());

        if ($signature !== '') {
            $this->write($directory . '/' . self::SIGNATURE, $signature);
        }

        $this->write($directory . '/' . self::RELEASE, (string) json_encode([
            'channel' => $channel,
            'sha256' => Hash::string($bytes),
            'size' => strlen($bytes),
            'published_at' => gmdate('c'),
            'signed' => $signature !== '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $directory;
    }

    /**
     * The package bytes of one release.
     *
     * @throws ArchiveException
     */
    public function bytes(string $slug, string $version): string
    {
        $file = $this->file($slug, $version, self::PACKAGE);

        if (!is_file($file)) {
            throw new ArchiveException("No package for {$slug} {$version}.");
        }

        $bytes = file_get_contents($file);

        if ($bytes === false) {
            throw new ArchiveException("Could not read the package for {$slug} {$version}.");
        }

        return $bytes;
    }

    public function signature(string $slug, string $version): string
    {
        $file = $this->file($slug, $version, self::SIGNATURE);

        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * Opens and verifies a stored package.
     *
     * @throws ArchiveException
     */
    public function package(string $slug, string $version): Package
    {
        return Package::fromString($this->bytes($slug, $version));
    }

    /** Deletes one release. Returns whether anything was removed. */
    public function forget(string $slug, string $version): bool
    {
        $directory = $this->directory($slug, $version);

        if (!is_dir($directory)) {
            return false;
        }

        foreach ((array) glob($directory . '/*') as $file) {
            @unlink((string) $file);
        }

        $removed = @rmdir($directory);

        // Drop the package directory too when that was its last version.
        $parent = $this->root . '/' . $slug;

        if ($removed && $this->versions($slug) === [] && is_dir($parent)) {
            @rmdir($parent);
        }

        return $removed;
    }

    /** Records a download, best effort. */
    public function countDownload(string $slug): void
    {
        $file = $this->config->storage() . '/downloads.json';
        $counts = [];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            $counts = is_array($decoded) ? $decoded : [];
        }

        $counts[$slug] = (int) ($counts[$slug] ?? 0) + 1;

        // A lost count is not worth failing a download over, so this is
        // unlocked and unchecked.
        @file_put_contents($file, (string) json_encode($counts), LOCK_EX);
    }

    /** @return array<string,int> */
    public function downloads(): array
    {
        $file = $this->config->storage() . '/downloads.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** @throws ArchiveException */
    private function write(string $file, string $contents): void
    {
        $temporary = $file . '.tmp';

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new ArchiveException("Could not write {$file}");
        }

        if (is_file($file) && !@unlink($file)) {
            @unlink($temporary);

            throw new ArchiveException("Could not replace {$file}");
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new ArchiveException("Could not move {$file} into place.");
        }
    }
}
