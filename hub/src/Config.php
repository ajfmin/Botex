<?php

namespace Hub;

/**
 * Reads hub/config.php, with defaults for anything absent.
 *
 * Deliberately not the bot's Config class: the hub shares only the archive
 * format with the bot, and pulling a second namespace across would drag its
 * dependencies here too.
 */
class Config
{
    /** @var array<string,mixed> */
    private array $items;

    /** @param array<string,mixed> $items */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function load(?string $file = null): self
    {
        $file ??= dirname(__DIR__) . '/config.php';

        $items = is_file($file) ? require $file : [];

        return new self(is_array($items) ? $items : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function name(): string
    {
        return (string) $this->get('name', 'Botex Archive');
    }

    public function tagline(): string
    {
        return (string) $this->get('tagline', '');
    }

    public function footer(): string
    {
        return (string) $this->get('footer', '');
    }

    public function storage(): string
    {
        return rtrim((string) $this->get('storage', dirname(__DIR__) . '/storage'), '/\\');
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) $this->get('per_page', 20)));
    }

    public function cacheSeconds(): int
    {
        return max(0, (int) $this->get('cache_seconds', 300));
    }

    /** @return array<string> */
    public function channels(): array
    {
        $channels = array_values(array_filter(
            array_map('strval', (array) $this->get('channels', ['stable'])),
            static fn (string $c): bool => preg_match('/^[a-z0-9_-]+$/i', $c) === 1
        ));

        return $channels === [] ? ['stable'] : $channels;
    }

    public function defaultChannel(): string
    {
        return $this->channels()[0];
    }

    /** Validates a channel from a query string against the allowed list. */
    public function channel(?string $requested): string
    {
        $requested = trim((string) $requested);

        return in_array($requested, $this->channels(), true)
            ? $requested
            : $this->defaultChannel();
    }

    public function signingKey(): string
    {
        return (string) $this->get('signing_key', '');
    }

    public function signingKeyPassphrase(): string
    {
        return (string) $this->get('signing_key_passphrase', '');
    }

    public function signs(): bool
    {
        return $this->signingKey() !== '';
    }

    public function uploadToken(): string
    {
        return (string) $this->get('upload_token', '');
    }

    public function allowsUpload(): bool
    {
        return $this->uploadToken() !== '';
    }

    /**
     * The public base URL.
     *
     * Falls back to the current request, which is right for a simple
     * deployment. Behind a proxy, set `url` explicitly -- the forwarded
     * headers are not trusted here, since anyone can send them and they
     * would end up in the install commands the site displays.
     */
    public function url(): string
    {
        $configured = trim((string) $this->get('url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            return '';
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

        return ($https ? 'https://' : 'http://') . $host;
    }
}
