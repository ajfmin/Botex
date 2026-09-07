<?php

namespace Botex\Remote;

use Botex\Support\Config;

/**
 * A small file cache for archive answers.
 *
 * Exists for one specific reason: the admin panel and the /admin section
 * want to say "2 updates available", and neither may make a webhook wait on
 * a network round trip to the archive. So the catalog is read from disk and
 * only refreshed when it has aged out.
 *
 * Files rather than a table because this is disposable: losing it costs one
 * HTTP request, and it must keep working before `migrate` has ever run.
 */
class Cache
{
    /** How long a cached answer is served for, in seconds. */
    public const TTL = 3600;

    private string $path;

    private int $ttl;

    public function __construct(Config $config)
    {
        $this->path = rtrim(
            (string) $config->get('paths.storage', dirname(__DIR__, 2) . '/storage'),
            '/\\'
        ) . '/remote-cache';

        $this->ttl = max(0, (int) $config->get('archive.cache_ttl', self::TTL));
    }

    /**
     * A cached value, or null when absent, expired or unreadable.
     *
     * @return array<mixed>|null
     */
    public function get(string $key): ?array
    {
        if ($this->ttl === 0) {
            return null;
        }

        $file = $this->file($key);

        if (!is_file($file)) {
            return null;
        }

        $age = time() - (int) filemtime($file);

        if ($age > $this->ttl) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        // A truncated or corrupt cache file is simply a miss. It is never
        // worth failing a command over something this disposable.
        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $value */
    public function put(string $key, array $value): void
    {
        if ($this->ttl === 0) {
            return;
        }

        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            return;
        }

        @file_put_contents(
            $this->file($key),
            (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function forget(string $key): void
    {
        @unlink($this->file($key));
    }

    /** Drops everything. Called by any command that changes what is installed. */
    public function flush(): int
    {
        $dropped = 0;

        foreach ((array) glob($this->path . '/*.json') as $file) {
            if (@unlink((string) $file)) {
                $dropped++;
            }
        }

        return $dropped;
    }

    /** Age of a cached entry in seconds, or null when there is none. */
    public function age(string $key): ?int
    {
        $file = $this->file($key);

        return is_file($file) ? time() - (int) filemtime($file) : null;
    }

    /** Hashed, since a key holds a URL and a query string. */
    private function file(string $key): string
    {
        return $this->path . '/' . hash('sha256', $key) . '.json';
    }
}
