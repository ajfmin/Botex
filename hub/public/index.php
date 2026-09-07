<?php

/**
 * The archive's front door: the website and the API behind one router.
 *
 * Point a vhost's document root here. Everything else -- packages, the
 * index, keys -- lives in hub/storage, outside this directory, so a
 * misconfigured server cannot serve a private key as a static file.
 *
 * Works without URL rewriting: paths are read from PATH_INFO when a rewrite
 * is present and from ?p= otherwise, so it runs on PHP's built-in server
 * and on shared hosting with no .htaccess support.
 */

use Hub\Api;
use Hub\Autoload;
use Hub\Catalog;
use Hub\Config;
use Hub\Store;
use Hub\View;

require dirname(__DIR__) . '/src/Autoload.php';

Autoload::register();

$config = Config::load();
$store = new Store($config);
$catalog = new Catalog($config, $store);

/**
 * The requested path as clean segments.
 *
 * @return array<string>
 */
$segments = (function (): array {
    $path = $_SERVER['PATH_INFO'] ?? null;

    if ($path === null || $path === '') {
        // No rewriting: /index.php/browse and ?p=browse both work.
        $path = (string) ($_GET['p'] ?? '');

        if ($path === '') {
            $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

            // Strip the script's own directory, so the app works whether it
            // sits at a domain root or in a subfolder.
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }

            $path = str_starts_with($uri, '/index.php')
                ? substr($uri, strlen('/index.php'))
                : $uri;
        }
    }

    return array_values(array_filter(
        explode('/', trim(str_replace('\\', '/', $path), '/')),
        static fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..'
    ));
})();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// The API lives under the same entry point so a deployment is one vhost.
if (($segments[0] ?? '') === 'api') {
    // api/v1/... -- the version segment is required and checked, so a
    // future v2 cannot be reached by accident.
    if (($segments[1] ?? '') !== 'v1') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unknown API version.']);
        exit;
    }

    (new Api($config, $store, $catalog))->handle(array_slice($segments, 2), $method);
    exit;
}

$view = new View($config);
$channel = $config->channel($_GET['channel'] ?? null);

try {
    switch ($segments[0] ?? '') {

        case '':
            // Home: search box, the core release, and a few extensions.
            $extensions = $catalog->extensions($channel);

            $popular = $extensions;
            uasort($popular, static fn (array $a, array $b): int =>
                ($b['downloads'] ?? 0) <=> ($a['downloads'] ?? 0));

            $view->render('home', [
                'channel' => $channel,
                'core' => $catalog->core($channel),
                'total' => count($extensions),
                'featured' => array_slice($popular, 0, 6, true),
                'recent' => $view->newest($extensions, 6),
            ]);

            break;

        case 'browse':
            $query = trim((string) ($_GET['q'] ?? ''));

            $results = $query === ''
                ? array_values($catalog->extensions($channel))
                : $catalog->search($query, $channel);

            $sort = (string) ($_GET['sort'] ?? 'name');

            usort($results, match ($sort) {
                'downloads' => static fn (array $a, array $b): int =>
                    ($b['downloads'] ?? 0) <=> ($a['downloads'] ?? 0),
                'updated' => static fn (array $a, array $b): int =>
                    strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')),
                default => static fn (array $a, array $b): int =>
                    strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
            });

            $perPage = $config->perPage();
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $pages = max(1, (int) ceil(count($results) / $perPage));
            $page = min($page, $pages);

            $view->render('browse', [
                'channel' => $channel,
                'query' => $query,
                'sort' => $sort,
                'results' => array_slice($results, ($page - 1) * $perPage, $perPage),
                'total' => count($results),
                'page' => $page,
                'pages' => $pages,
            ]);

            break;

        case 'package':
            $slug = $segments[1] ?? '';

            if ($slug === '') {
                $view->notFound();
                break;
            }

            Store::assertSlug($slug);

            $package = $catalog->find($slug, $channel);

            if ($package === null) {
                $view->notFound("There is no package called '{$slug}' on {$channel}.");
                break;
            }

            $view->render('package', [
                'channel' => $channel,
                'package' => $package,
                // A specific version can be inspected without changing which
                // one the install command names.
                'selected' => $segments[2] ?? null,
            ]);

            break;

        case 'docs':
            $view->render('docs', ['channel' => $channel]);
            break;

        default:
            $view->notFound();
    }
} catch (\Botex\Archive\ArchiveException $e) {
    $view->notFound($e->getMessage());
} catch (\Throwable $e) {
    error_log('Botex hub: ' . $e->getMessage());

    http_response_code(500);
    $view->render('error', ['channel' => $channel]);
}
