<?php

namespace Hub;

/**
 * Renders the website: plain PHP templates, no engine, no build step.
 *
 * Deliberately dependency-free. The hub is meant to be uploaded to whatever
 * host you have, and a template engine would mean composer on the server
 * for a handful of pages.
 *
 * Every template receives $view and escapes through $view->e(). Nothing
 * from a package -- name, description, changelog, readme -- is trusted:
 * package metadata is authored by whoever published it, so on a shared
 * archive it is as untrusted as any user input.
 */
class View
{
    private string $path;

    public function __construct(
        private Config $config
    ) {
        $this->path = dirname(__DIR__) . '/views';
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): void
    {
        $file = $this->path . '/' . basename($template) . '.php';

        if (!is_file($file)) {
            $this->notFound();

            return;
        }

        // Available to the template as $view, plus each key by name.
        $view = $this;
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        header('Content-Type: text/html; charset=utf-8');

        require $this->path . '/layout.php';
    }

    public function notFound(string $message = 'That page does not exist.'): void
    {
        http_response_code(404);

        $view = $this;
        $content = '<h1>Not found</h1><p class="muted">' . $this->e($message) . '</p>'
            . '<p><a class="button" href="' . $this->url('browse') . '">Browse extensions</a></p>';
        $channel = $this->config->defaultChannel();

        require $this->path . '/layout.php';
    }

    /** HTML-escape. */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A site URL.
     *
     * Built as ?p= when no rewriting is in play, which is what makes the
     * site work unchanged on PHP's built-in server and on hosting without
     * .htaccess support.
     *
     * @param array<string,string|int> $query
     */
    public function url(string $path = '', array $query = []): string
    {
        $path = trim($path, '/');
        $base = $this->scriptBase();

        if ($path !== '') {
            $query = ['p' => $path] + $query;
        }

        return $base . ($query === [] ? '' : '?' . http_build_query($query));
    }

    public function packageUrl(string $slug, ?string $version = null): string
    {
        return $this->url('package/' . rawurlencode($slug) . ($version === null ? '' : '/' . rawurlencode($version)));
    }

    /** Absolute URL to a release download, shown on a package page. */
    public function downloadUrl(string $slug, string $version): string
    {
        $base = $this->config->url();

        return ($base === '' ? $this->scriptBase() : $base)
            . '/api/v1/download/' . rawurlencode($slug) . '/' . rawurlencode($version);
    }

    private function scriptBase(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');

        return $script === '' ? '/' : $script;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function name(): string
    {
        return $this->config->name();
    }

    /** "2.4 KB", "150 KB", "1.2 MB" */
    public function size(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    /** "3 days ago", from an ISO 8601 string. */
    public function ago(string $timestamp): string
    {
        if ($timestamp === '') {
            return 'unknown';
        }

        $then = strtotime($timestamp);

        if ($then === false) {
            return 'unknown';
        }

        $seconds = max(0, time() - $then);

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => (int) ($seconds / 60) . ' minutes ago',
            $seconds < 86400 => (int) ($seconds / 3600) . ' hours ago',
            $seconds < 2592000 => (int) ($seconds / 86400) . ' days ago',
            $seconds < 31536000 => (int) ($seconds / 2592000) . ' months ago',
            default => (int) ($seconds / 31536000) . ' years ago',
        };
    }

    public function date(string $timestamp): string
    {
        $then = strtotime($timestamp);

        return $then === false ? '-' : gmdate('j M Y', $then);
    }

    /**
     * The newest N packages by publication date.
     *
     * @param  array<string,array<string,mixed>> $packages
     * @return array<string,array<string,mixed>>
     */
    public function newest(array $packages, int $limit): array
    {
        uasort($packages, static fn (array $a, array $b): int =>
            strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));

        return array_slice($packages, 0, $limit, true);
    }

    /**
     * A tiny, safe subset of Markdown for readmes and changelogs.
     *
     * Everything is escaped first, then a handful of patterns are turned
     * back into tags. That ordering is the whole security model: no
     * attacker-supplied angle bracket can survive into the output, so a
     * readme cannot inject script even though the field is rendered as
     * HTML. A real Markdown parser would be a dependency and a much larger
     * surface for exactly this risk.
     */
    public function markdown(string $text): string
    {
        $escaped = $this->e(trim($text));

        if ($escaped === '') {
            return '';
        }

        // Fenced code blocks first, so their contents are not touched by the
        // inline rules below.
        $escaped = (string) preg_replace_callback(
            '/^```[a-z]*\n(.*?)\n```$/ms',
            static fn (array $m): string => '<pre><code>' . $m[1] . '</code></pre>',
            $escaped
        );

        $lines = explode("\n", $escaped);
        $html = [];
        $inList = false;
        $inCode = false;

        foreach ($lines as $line) {
            // Pass through anything inside a <pre> produced above.
            if (str_contains($line, '<pre>')) {
                $inCode = true;
            }

            if ($inCode) {
                $html[] = $line;

                if (str_contains($line, '</pre>')) {
                    $inCode = false;
                }

                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }

                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $matches) === 1) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }

                // Offset by one so a readme's h1 does not compete with the
                // page's own heading.
                $level = min(5, strlen($matches[1]) + 1);
                $html[] = "<h{$level}>" . $this->inline($matches[2]) . "</h{$level}>";

                continue;
            }

            if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $matches) === 1) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }

                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';

                continue;
            }

            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            $html[] = '<p>' . $this->inline($trimmed) . '</p>';
        }

        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    /** Inline code, bold and italics, on already-escaped text. */
    private function inline(string $text): string
    {
        $text = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace('/(?<![*\w])\*([^*]+)\*(?![*\w])/', '<em>$1</em>', $text);
    }
}
