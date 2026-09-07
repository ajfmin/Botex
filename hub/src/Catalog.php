<?php

namespace Hub;

use Botex\Archive\Manifest;

/**
 * The catalog: what the website browses and the API serves.
 *
 * Built from the packages on disk and cached in storage/index.json, because
 * assembling it means reading a manifest per release and that should not
 * happen on every page view. `hub reindex` rebuilds it; publishing does so
 * automatically.
 *
 * The index is a cache, not the source of truth. A missing or stale
 * index.json is rebuilt on demand, so deleting it is always safe.
 */
class Catalog
{
    public const INDEX = 'index.json';

    /** @var array<string,mixed>|null */
    private ?array $index = null;

    public function __construct(
        private Config $config,
        private Store $store
    ) {
    }

    /**
     * Every package, newest release first within each channel.
     *
     * @return array<string,array<string,mixed>> slug => entry
     */
    public function all(string $channel): array
    {
        $index = $this->index();
        $packages = [];

        foreach ((array) ($index['packages'] ?? []) as $slug => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $release = $this->pick($entry, $channel);

            if ($release !== null) {
                $packages[(string) $slug] = $release;
            }
        }

        ksort($packages);

        return $packages;
    }

    /** Extensions only, which is what the website lists. */
    public function extensions(string $channel): array
    {
        return array_filter(
            $this->all($channel),
            static fn (array $entry): bool => ($entry['type'] ?? '') !== Manifest::TYPE_CORE
        );
    }

    /** The core release on a channel, or null. */
    public function core(string $channel): ?array
    {
        $all = $this->all($channel);

        return $all['core'] ?? null;
    }

    /**
     * One package, with its full version history.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $slug, string $channel): ?array
    {
        $entry = $this->index()['packages'][$slug] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        return $this->pick($entry, $channel);
    }

    /**
     * Free-text search over slug, name and description.
     *
     * @return array<array<string,mixed>>
     */
    public function search(string $query, string $channel): array
    {
        $needle = mb_strtolower(trim($query));

        if ($needle === '') {
            return array_values($this->extensions($channel));
        }

        $matches = [];

        foreach ($this->extensions($channel) as $entry) {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($entry['slug'] ?? ''),
                (string) ($entry['name'] ?? ''),
                (string) ($entry['description'] ?? ''),
                implode(' ', (array) ($entry['keywords'] ?? [])),
            ]));

            if (!str_contains($haystack, $needle)) {
                continue;
            }

            // A hit in the name outranks one buried in a description.
            $entry['_score'] = str_contains(mb_strtolower((string) ($entry['slug'] ?? '')), $needle) ? 0
                : (str_contains(mb_strtolower((string) ($entry['name'] ?? '')), $needle) ? 1 : 2);

            $matches[] = $entry;
        }

        usort($matches, static function (array $a, array $b): int {
            return [$a['_score'], $a['slug'] ?? ''] <=> [$b['_score'], $b['slug'] ?? ''];
        });

        return array_map(static function (array $entry): array {
            unset($entry['_score']);

            return $entry;
        }, $matches);
    }

    /**
     * The cached index, rebuilding it when absent.
     *
     * @return array<string,mixed>
     */
    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $file = $this->config->storage() . '/' . self::INDEX;

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded) && isset($decoded['packages'])) {
                return $this->index = $decoded;
            }
        }

        return $this->index = $this->rebuild();
    }

    /**
     * Rebuilds the index from disk and writes it.
     *
     * @return array<string,mixed>
     */
    public function rebuild(): array
    {
        $downloads = $this->store->downloads();
        $packages = [];

        foreach ($this->store->slugs() as $slug) {
            $releases = [];

            foreach ($this->store->versions($slug) as $version) {
                try {
                    $manifest = $this->store->manifest($slug, $version);
                } catch (\Throwable) {
                    // One unreadable release must not take the archive down;
                    // it is simply not listed.
                    continue;
                }

                $release = $this->store->release($slug, $version);

                $releases[$version] = [
                    'version' => $version,
                    'channel' => $release['channel'],
                    'sha256' => $release['sha256'],
                    'size' => $release['size'],
                    'signed' => $release['signed'],
                    'published_at' => $release['published_at'],
                    'requires' => $manifest->requires,
                    'changelog' => $manifest->changelog,
                    'files' => count($manifest->files),
                ];
            }

            if ($releases === []) {
                continue;
            }

            // Descriptive fields come from the newest release, since that is
            // the current description of the package.
            $newestVersion = (string) array_key_first($releases);

            try {
                $newest = $this->store->manifest($slug, $newestVersion);
            } catch (\Throwable) {
                continue;
            }

            $packages[$slug] = [
                'slug' => $slug,
                'type' => $newest->type,
                'name' => $newest->name,
                'description' => $newest->description,
                'commands' => $newest->commands === []
                    ? Manifest::defaultCommands($newest->type, $slug)
                    : $newest->commands,
                'author' => (string) ($newest->extra['author'] ?? ''),
                'keywords' => array_values(array_filter(
                    (array) ($newest->extra['keywords'] ?? []),
                    'is_string'
                )),
                'homepage' => (string) ($newest->extra['homepage'] ?? ''),
                'readme' => $newest->readme,
                'downloads' => (int) ($downloads[$slug] ?? 0),
                'releases' => $releases,
            ];
        }

        ksort($packages);

        $index = [
            'generated_at' => gmdate('c'),
            'channels' => $this->config->channels(),
            'packages' => $packages,
        ];

        $this->write($index);

        return $this->index = $index;
    }

    /**
     * Flattens a package's releases down to the newest on one channel.
     *
     * The shape the bot's Listing expects: current version at the top level,
     * the rest under `versions`.
     *
     * @param  array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function pick(array $entry, string $channel): ?array
    {
        $releases = (array) ($entry['releases'] ?? []);
        $onChannel = array_values(array_filter(
            $releases,
            static fn ($release): bool => is_array($release) && ($release['channel'] ?? '') === $channel
        ));

        if ($onChannel === []) {
            return null;
        }

        // Already newest-first from rebuild(), which sorted the versions.
        $current = $onChannel[0];

        return [
            'slug' => $entry['slug'] ?? '',
            'type' => $entry['type'] ?? Manifest::TYPE_EXTENSION,
            'name' => $entry['name'] ?? '',
            'description' => $entry['description'] ?? '',
            'version' => $current['version'] ?? '',
            'sha256' => $current['sha256'] ?? '',
            'size' => $current['size'] ?? 0,
            'signed' => $current['signed'] ?? false,
            'published_at' => $current['published_at'] ?? '',
            'requires' => $current['requires'] ?? [],
            'changelog' => $current['changelog'] ?? '',
            'commands' => $entry['commands'] ?? [],
            'author' => $entry['author'] ?? '',
            'keywords' => $entry['keywords'] ?? [],
            'homepage' => $entry['homepage'] ?? '',
            'readme' => $entry['readme'] ?? '',
            'downloads' => $entry['downloads'] ?? 0,
            'versions' => array_column($onChannel, 'version'),
            'releases' => $onChannel,
        ];
    }

    /** @param array<string,mixed> $index */
    private function write(array $index): void
    {
        $directory = $this->config->storage();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $file = $directory . '/' . self::INDEX;
        $temporary = $file . '.tmp';

        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) === false) {
            return;
        }

        if (is_file($file)) {
            @unlink($file);
        }

        @rename($temporary, $file);
    }
}
