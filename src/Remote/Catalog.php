<?php

namespace Botex\Remote;

use Botex\Support\Config;

/**
 * Browsing and searching the archive.
 *
 * The read-only half of the remote surface: everything here answers "what
 * is available", never "install it". Results are cached on disk for a
 * while, so the admin panel can show "2 updates available" without a
 * webhook ever waiting on a network call -- see Cache.
 */
class Catalog
{
    /** API paths on the hub. Versioned, so a hub can serve an old bot. */
    private const INDEX = 'api/v1/index';
    private const SEARCH = 'api/v1/search';
    private const PACKAGE = 'api/v1/package';

    private string $channel;

    public function __construct(
        private Client $client,
        private Cache $cache,
        Config $config
    ) {
        $this->channel = (string) $config->get('archive.channel', 'stable');
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * Every package the archive publishes.
     *
     * @param  bool $fresh bypass the cache
     * @return array<string, Listing> keyed by slug
     *
     * @throws RemoteException
     */
    public function all(bool $fresh = false): array
    {
        $data = $this->fetch(self::INDEX, ['channel' => $this->channel], $fresh);
        $listings = [];

        foreach ((array) ($data['packages'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $listing = Listing::fromArray($entry);

            // A malformed entry is skipped rather than fatal: one bad
            // package on the hub must not make the whole catalog unusable.
            if ($listing->isValid()) {
                $listings[$listing->slug] = $listing;
            }
        }

        ksort($listings);

        return $listings;
    }

    /**
     * Extensions only, which is what `ext:remote` lists.
     *
     * @return array<string, Listing>
     *
     * @throws RemoteException
     */
    public function extensions(bool $fresh = false): array
    {
        return array_filter($this->all($fresh), static fn (Listing $l): bool => !$l->isCore());
    }

    /**
     * Full detail for one package, including its version history.
     *
     * @throws NotFound       when the archive has no such package
     * @throws RemoteException
     */
    public function find(string $slug, bool $fresh = false): Listing
    {
        $data = $this->fetch(
            self::PACKAGE . '/' . rawurlencode($slug),
            ['channel' => $this->channel],
            $fresh
        );

        $listing = Listing::fromArray($data['package'] ?? $data);

        if (!$listing->isValid()) {
            throw new NotFound("The archive has no usable package '{$slug}'.");
        }

        return $listing;
    }

    /**
     * Free-text search.
     *
     * Asked of the hub rather than filtered locally, so a large archive does
     * not have to be downloaded whole to find one extension.
     *
     * @return array<Listing>
     *
     * @throws RemoteException
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return array_values($this->extensions());
        }

        // Not cached: a search is typed once and its result is stale the
        // moment the archive changes, whereas the index is read repeatedly.
        $data = $this->client->json(self::SEARCH, [
            'q' => $query,
            'channel' => $this->channel,
        ]);

        $results = [];

        foreach ((array) ($data['packages'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $listing = Listing::fromArray($entry);

            if ($listing->isValid()) {
                $results[] = $listing;
            }
        }

        return $results;
    }

    /**
     * The core release the archive currently offers.
     *
     * @throws RemoteException
     */
    public function core(bool $fresh = false): ?Listing
    {
        try {
            $listing = $this->find('core', $fresh);
        } catch (NotFound) {
            // An archive that publishes only extensions is perfectly valid.
            return null;
        }

        return $listing->isCore() ? $listing : null;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    /**
     * The index as last cached, without ever touching the network.
     *
     * For surfaces that run inside a webhook: they may show slightly stale
     * information, but they may never make Telegram wait on an HTTP request
     * to a third-party host that could be slow or down. Returns null when
     * nothing has been cached yet, which reads as "not known" rather than
     * "nothing available" -- the difference matters, because the second is a
     * claim this method cannot make.
     *
     * Being a separate method rather than a flag is deliberate: a caller
     * cannot reach the network from here by passing the wrong argument.
     *
     * @return array<string, Listing>|null
     */
    public function cachedExtensions(): ?array
    {
        $data = $this->cache->get($this->key(self::INDEX, ['channel' => $this->channel]));

        if ($data === null) {
            return null;
        }

        $listings = [];

        foreach ((array) ($data['packages'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $listing = Listing::fromArray($entry);

            if ($listing->isValid() && !$listing->isCore()) {
                $listings[$listing->slug] = $listing;
            }
        }

        ksort($listings);

        return $listings;
    }

    /**
     * The cached core release, or null when it is not known.
     *
     * Same webhook rule as cachedExtensions().
     */
    public function cachedCore(): ?Listing
    {
        $data = $this->cache->get(
            $this->key(self::PACKAGE . '/core', ['channel' => $this->channel])
        );

        if ($data === null) {
            return null;
        }

        $listing = Listing::fromArray($data['package'] ?? $data);

        return $listing->isValid() && $listing->isCore() ? $listing : null;
    }

    /**
     * A cached GET.
     *
     * @param  array<string,string|int> $query
     * @return array<mixed>
     *
     * @throws RemoteException
     */
    private function fetch(string $path, array $query, bool $fresh): array
    {
        $key = $this->key($path, $query);

        if (!$fresh) {
            $cached = $this->cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $this->client->json($path, $query);

        $this->cache->put($key, $data);

        return $data;
    }

    /**
     * The cache key for a request.
     *
     * One method so the cached-only readers above look up exactly what
     * fetch() wrote. When these drifted apart, `--fresh` refreshed one key
     * and the panel kept reading another.
     *
     * @param array<string,string|int> $query
     */
    private function key(string $path, array $query): string
    {
        return 'catalog:' . $path . ':' . http_build_query($query);
    }
}
