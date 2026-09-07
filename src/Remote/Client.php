<?php

namespace Botex\Remote;

use Botex\Support\Config;

/**
 * The only place Botex talks to the archive host.
 *
 * Deliberately small: GET a URL, come back with bytes or throw. No caching,
 * no retries, no parsing -- those belong to the callers, which have the
 * context to decide what a failure means.
 *
 * Everything about *where* it connects comes from config('archive'), which
 * the operator edits in config/config.php by hand rather than through .env.
 * That is intentional: the archive you trust to ship you executable code is
 * a deployment decision, and it should be visible in a file that is read in
 * review, not in an environment variable that can be changed without one.
 */
class Client
{
    /** Refuse a response larger than this; a package is not this big. */
    public const MAX_BYTES = 268435456;   // 256 MB

    private string $base;

    private bool $verifyTls;

    private int $timeout;

    public function __construct(Config $config)
    {
        $this->base = rtrim((string) $config->get('archive.url', ''), '/');
        $this->verifyTls = (bool) $config->get('archive.verify_tls', true);
        $this->timeout = max(1, min(300, (int) $config->get('archive.timeout', 20)));
    }

    /** Whether an archive has been configured at all. */
    public function configured(): bool
    {
        return $this->base !== '';
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Fetches a path relative to the archive base.
     *
     * @param  array<string,string|int> $query
     *
     * @throws RemoteException
     */
    public function get(string $path, array $query = []): string
    {
        return $this->request($this->url($path, $query));
    }

    /**
     * Fetches and JSON-decodes.
     *
     * @param  array<string,string|int> $query
     * @return array<mixed>
     *
     * @throws RemoteException
     */
    public function json(string $path, array $query = []): array
    {
        $url = $this->url($path, $query);
        $body = $this->request($url);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RemoteException("The archive returned something that is not JSON: {$url}");
        }

        // The hub reports its own failures in the body, with a 200, so that
        // a proxy cannot turn a meaningful error into a bare status code.
        if (isset($data['error']) && is_string($data['error'])) {
            throw new RemoteException('The archive refused: ' . $data['error']);
        }

        return $data;
    }

    /**
     * Builds an absolute URL, refusing anything that leaves the archive.
     *
     * @param  array<string,string|int> $query
     *
     * @throws RemoteException
     */
    public function url(string $path, array $query = []): string
    {
        if (!$this->configured()) {
            throw new RemoteException(
                'No archive is configured. Set archive.url in config/config.php.'
            );
        }

        // A path is always relative to the base. Accepting an absolute URL
        // here would let a hub's own response redirect the next request to
        // any host it likes, which is exactly the trust boundary this class
        // exists to hold.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            throw new RemoteException('Refusing to fetch an absolute URL.');
        }

        $url = $this->base . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * One HTTP GET.
     *
     * @throws RemoteException
     */
    private function request(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // Plain HTTP is allowed only for a loopback host, which is how you
        // develop against a local hub. Anywhere else it would mean fetching
        // code over a channel anyone on the path can rewrite.
        if ($scheme !== 'https' && !$this->isLoopback($url)) {
            throw new RemoteException(
                'The archive must be reached over HTTPS. Only localhost may use plain HTTP.'
            );
        }

        if (!function_exists('curl_init')) {
            throw new RemoteException('This PHP has no curl, so the archive cannot be reached.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RemoteException("Could not prepare a request for {$url}");
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            // A redirect chain is where an open redirect turns into fetching
            // from somewhere else entirely, so it is short and, below,
            // restricted to the same scheme.
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout * 2,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_USERAGENT => 'Botex/' . \Botex\Botex::VERSION,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_FAILONERROR => false,
            // Abort a response that is growing past the ceiling instead of
            // discovering it after it has been buffered.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($handle, $expected, $received): int {
                return ($expected > self::MAX_BYTES || $received > self::MAX_BYTES) ? 1 : 0;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);

        curl_close($handle);

        if ($body === false) {
            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new RemoteException('The archive response was larger than the limit.');
            }

            throw new RemoteException("Could not reach the archive: {$error}");
        }

        if ($status === 404) {
            throw new NotFound("The archive has no {$url}");
        }

        if ($status < 200 || $status >= 300) {
            throw new RemoteException("The archive answered {$status} for {$url}");
        }

        if (strlen($body) > self::MAX_BYTES) {
            throw new RemoteException('The archive response was larger than the limit.');
        }

        return $body;
    }

    /** Whether a URL points at this machine. */
    private function isLoopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
