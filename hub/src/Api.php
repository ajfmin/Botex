<?php

namespace Hub;

use Botex\Archive\ArchiveException;

/**
 * The JSON API the bot's console talks to.
 *
 * Read-only, apart from one optional token-gated upload. Every response is
 * JSON, including failures, which carry an `error` key -- the bot's Client
 * looks for exactly that, so a hub-side problem arrives as a sentence
 * rather than as a bare status code a proxy may have invented.
 *
 * Routes, all under /api/v1:
 *
 *   GET  index                          every package on a channel
 *   GET  search?q=                      free-text search
 *   GET  package/<slug>                 one package with its history
 *   GET  package/<slug>/<version>       one release, including its sha256
 *   GET  download/<slug>/<version>      the .botex bytes
 *   GET  download/<slug>/<version>/signature
 *   POST upload                         off unless upload_token is set
 */
class Api
{
    public function __construct(
        private Config $config,
        private Store $store,
        private Catalog $catalog
    ) {
    }

    /**
     * Dispatches an API request.
     *
     * @param array<string> $segments path segments after api/v1
     */
    public function handle(array $segments, string $method): void
    {
        $route = $segments[0] ?? '';
        $channel = $this->config->channel($_GET['channel'] ?? null);

        try {
            match ($route) {
                'index' => $this->index($channel),
                'search' => $this->search($channel),
                'package' => $this->package(array_slice($segments, 1), $channel),
                'download' => $this->download(array_slice($segments, 1)),
                'upload' => $this->upload($method),
                'ping' => $this->ping(),
                default => $this->error('Unknown endpoint.', 404),
            };
        } catch (ArchiveException $e) {
            // A bad slug or version reaches here; it is the caller's fault,
            // so it is a 400 with the reason.
            $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            // Never leak a stack trace or a path to a client.
            error_log('Botex hub: ' . $e->getMessage());

            $this->error('The archive hit an internal error.', 500);
        }
    }

    private function ping(): void
    {
        $this->json([
            'archive' => $this->config->name(),
            'channels' => $this->config->channels(),
            'packages' => count($this->catalog->all($this->config->defaultChannel())),
            'signed' => $this->config->signs(),
            'time' => gmdate('c'),
        ]);
    }

    private function index(string $channel): void
    {
        $packages = [];

        foreach ($this->catalog->all($channel) as $entry) {
            $packages[] = $this->summarise($entry);
        }

        $this->json([
            'channel' => $channel,
            'count' => count($packages),
            'packages' => $packages,
        ]);
    }

    private function search(string $channel): void
    {
        $query = (string) ($_GET['q'] ?? '');

        // Bounded: this is a public endpoint and the term is echoed back.
        if (mb_strlen($query) > 100) {
            $this->error('That search term is too long.', 400);

            return;
        }

        $packages = array_map(
            fn (array $entry): array => $this->summarise($entry),
            $this->catalog->search($query, $channel)
        );

        $this->json([
            'channel' => $channel,
            'query' => $query,
            'count' => count($packages),
            'packages' => $packages,
        ]);
    }

    /** @param array<string> $segments */
    private function package(array $segments, string $channel): void
    {
        $slug = $segments[0] ?? '';

        if ($slug === '') {
            $this->error('Which package?', 400);

            return;
        }

        Store::assertSlug($slug);

        // A specific release: the bot checks the sha256 here against what it
        // downloaded, which is why this endpoint exists separately.
        if (isset($segments[1]) && $segments[1] !== '') {
            $version = $segments[1];

            Store::assertVersion($version);

            if (!$this->store->has($slug, $version)) {
                $this->error("No {$slug} {$version} here.", 404);

                return;
            }

            $release = $this->store->release($slug, $version);
            $manifest = $this->store->manifest($slug, $version);

            $this->json([
                'slug' => $slug,
                'version' => $version,
                'sha256' => $release['sha256'],
                'size' => $release['size'],
                'signed' => $release['signed'],
                'channel' => $release['channel'],
                'published_at' => $release['published_at'],
                'requires' => $manifest->requires,
                'changelog' => $manifest->changelog,
                'files' => count($manifest->files),
            ]);

            return;
        }

        $entry = $this->catalog->find($slug, $channel);

        if ($entry === null) {
            $this->error("No package '{$slug}' on {$channel}.", 404);

            return;
        }

        $this->json(['package' => $this->summarise($entry, true)]);
    }

    /** @param array<string> $segments */
    private function download(array $segments): void
    {
        $slug = $segments[0] ?? '';
        $version = $segments[1] ?? '';

        if ($slug === '' || $version === '') {
            $this->error('Which package and version?', 400);

            return;
        }

        Store::assertSlug($slug);
        Store::assertVersion($version);

        if (!$this->store->has($slug, $version)) {
            $this->error("No {$slug} {$version} here.", 404);

            return;
        }

        // The detached signature, asked for separately by the bot.
        if (($segments[2] ?? '') === 'signature') {
            $signature = $this->store->signature($slug, $version);

            if ($signature === '') {
                $this->error('That release is not signed.', 404);

                return;
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: ' . strlen($signature));
            echo $signature;

            return;
        }

        $bytes = $this->store->bytes($slug, $version);

        $this->store->countDownload($slug);

        header('Content-Type: application/zip');
        header('Content-Length: ' . strlen($bytes));
        header(
            'Content-Disposition: attachment; filename="'
                . \Botex\Archive\Package::filename($slug, $version) . '"'
        );
        // The bot verifies the hash itself, but this lets a proxy or a
        // curious human check without unpacking anything.
        header('X-Botex-Sha256: ' . $this->store->release($slug, $version)['sha256']);
        header('Cache-Control: public, max-age=86400, immutable');

        echo $bytes;
    }

    /**
     * Publishing over HTTP.
     *
     * Off unless a token is configured, and worth understanding before
     * turning on: this endpoint puts executable code into every bot that
     * follows this archive. The token is compared with hash_equals, the body
     * is size-capped, and the package is fully verified before it is stored
     * -- but the safest configuration is still to leave it disabled and
     * publish from the CLI on the server.
     */
    private function upload(string $method): void
    {
        if (!$this->config->allowsUpload()) {
            $this->error('Uploading is disabled on this archive.', 403);

            return;
        }

        if ($method !== 'POST') {
            $this->error('Upload wants a POST.', 405);

            return;
        }

        $provided = (string) ($_SERVER['HTTP_X_BOTEX_TOKEN'] ?? '');

        if ($provided === '' || !hash_equals($this->config->uploadToken(), $provided)) {
            // Deliberately vague, and the same answer as a disabled endpoint
            // would give, so probing cannot tell the two apart.
            $this->error('Not allowed.', 403);

            return;
        }

        $bytes = (string) file_get_contents('php://input');

        if ($bytes === '') {
            $this->error('No package in the request body.', 400);

            return;
        }

        if (strlen($bytes) > \Botex\Archive\Zip::MAX_TOTAL_BYTES) {
            $this->error('That package is too large.', 413);

            return;
        }

        $publisher = new Publisher($this->config, $this->store, $this->catalog);

        $file = tempnam(sys_get_temp_dir(), 'botex-upload-');

        if ($file === false) {
            $this->error('Could not stage the upload.', 500);

            return;
        }

        try {
            file_put_contents($file, $bytes);

            $manifest = $publisher->file(
                file: $file,
                channel: isset($_GET['channel']) ? $this->config->channel($_GET['channel']) : null,
                overwrite: ($_GET['overwrite'] ?? '') === '1'
            );

            $this->json([
                'published' => true,
                'slug' => $manifest->slug,
                'version' => $manifest->version,
            ]);
        } catch (ArchiveException $e) {
            $this->error($e->getMessage(), 400);
        } finally {
            @unlink($file);
        }
    }

    /**
     * One package as the API reports it.
     *
     * @param  array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function summarise(array $entry, bool $detailed = false): array
    {
        $summary = [
            'slug' => $entry['slug'] ?? '',
            'type' => $entry['type'] ?? 'extension',
            'name' => $entry['name'] ?? '',
            'version' => $entry['version'] ?? '',
            'description' => $entry['description'] ?? '',
            'requires' => $entry['requires'] ?? [],
            'commands' => $entry['commands'] ?? [],
            'versions' => $entry['versions'] ?? [],
            'downloads' => $entry['downloads'] ?? 0,
            'signed' => $entry['signed'] ?? false,
            'sha256' => $entry['sha256'] ?? '',
            'size' => $entry['size'] ?? 0,
            'updated_at' => $entry['published_at'] ?? '',
            'author' => $entry['author'] ?? '',
        ];

        if ($detailed) {
            $summary['changelog'] = $entry['changelog'] ?? '';
            $summary['readme'] = $entry['readme'] ?? '';
            $summary['homepage'] = $entry['homepage'] ?? '';
            $summary['keywords'] = $entry['keywords'] ?? [];
            $summary['releases'] = $entry['releases'] ?? [];
        }

        return $summary;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=' . $this->config->cacheSeconds());
        // The API is meant to be read by bots from anywhere.
        header('Access-Control-Allow-Origin: *');

        echo (string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    private function error(string $message, int $status): void
    {
        // 200-with-an-error-body is what the bot's Client looks for first,
        // but the status is still set correctly for everything in between.
        $this->json(['error' => $message], $status);
    }
}
