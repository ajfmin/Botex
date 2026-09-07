<?php

/**
 * Page shell. Receives $content (already rendered) and $view.
 *
 * The stylesheet is inline: one request, no asset pipeline, and nothing to
 * cache-bust when the hub is updated.
 *
 * @var Hub\View $view
 * @var string $content
 * @var string $channel
 */

$channels = $view->config()->channels();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->e($view->name()) ?></title>
    <meta name="description" content="<?= $view->e($view->config()->tagline()) ?>">
    <style>
        :root {
            --bg: #ffffff;
            --fg: #1f2328;
            --muted: #656d76;
            --line: #d1d9e0;
            --soft: #f6f8fa;
            --link: #0969da;
            --ok: #1a7f37;
            --warn: #9a6700;
            --radius: 6px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --fg: #e6edf3;
                --muted: #9198a1;
                --line: #3d444d;
                --soft: #151b23;
                --link: #4493f8;
                --ok: #3fb950;
                --warn: #d29922;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
        }

        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        code, pre {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
            font-size: .875em;
        }

        code {
            background: var(--soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: .1em .4em;
        }

        pre {
            background: var(--soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 1rem;
            overflow-x: auto;
        }

        pre code { background: none; border: 0; padding: 0; }

        header.site {
            border-bottom: 1px solid var(--line);
            background: var(--soft);
        }

        .wrap { max-width: 62rem; margin: 0 auto; padding: 0 1.25rem; }

        header.site .wrap {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            padding-top: 1rem;
            padding-bottom: 1rem;
            flex-wrap: wrap;
        }

        .brand {
            font-weight: 600;
            font-size: 1.0625rem;
            color: var(--fg);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .brand:hover { text-decoration: none; }

        .brand .mark {
            display: inline-grid;
            place-items: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: var(--radius);
            background: var(--fg);
            color: var(--bg);
            font-weight: 700;
            font-size: .875rem;
        }

        nav.site { margin-left: auto; display: flex; gap: 1.25rem; align-items: center; }
        nav.site a { color: var(--muted); font-size: .9375rem; }
        nav.site a:hover { color: var(--link); }

        main { padding: 2rem 0 4rem; }

        h1 { font-size: 1.5rem; margin: 0 0 .5rem; letter-spacing: -.01em; }
        h2 { font-size: 1.125rem; margin: 2rem 0 .75rem; }
        h3 { font-size: 1rem; margin: 1.5rem 0 .5rem; }

        .muted { color: var(--muted); }
        .small { font-size: .875rem; }

        .search { display: flex; gap: .5rem; margin: 1.25rem 0; }

        .search input[type=search] {
            flex: 1;
            padding: .5rem .75rem;
            font-size: .9375rem;
            color: var(--fg);
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
        }

        .search input[type=search]:focus {
            outline: 2px solid var(--link);
            outline-offset: -1px;
        }

        .button {
            display: inline-block;
            padding: .5rem .875rem;
            font-size: .9375rem;
            font-weight: 500;
            color: var(--bg);
            background: var(--fg);
            border: 1px solid transparent;
            border-radius: var(--radius);
            cursor: pointer;
        }

        .button:hover { text-decoration: none; opacity: .9; }

        .button.secondary {
            color: var(--fg);
            background: var(--soft);
            border-color: var(--line);
        }

        .cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }

        .card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg);
        }

        .card h3 { margin: 0 0 .25rem; font-size: 1rem; }
        .card p { margin: .25rem 0 .75rem; color: var(--muted); font-size: .875rem; }

        .list { border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
        .list .row { padding: 1rem; border-top: 1px solid var(--line); display: flex; gap: 1rem; }
        .list .row:first-child { border-top: 0; }
        .list .row:hover { background: var(--soft); }
        .list .row .body { flex: 1; min-width: 0; }
        .list .row h3 { margin: 0; font-size: 1rem; }
        .list .row p { margin: .25rem 0 0; color: var(--muted); font-size: .875rem; }

        .meta { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .5rem; color: var(--muted); font-size: .8125rem; }

        .tag {
            display: inline-block;
            padding: .0625rem .5rem;
            border: 1px solid var(--line);
            border-radius: 1rem;
            font-size: .75rem;
            color: var(--muted);
        }

        .tag.ok { color: var(--ok); border-color: currentColor; }
        .tag.core { color: var(--warn); border-color: currentColor; }

        table { border-collapse: collapse; width: 100%; font-size: .9375rem; }
        th, td { text-align: left; padding: .625rem .5rem; border-bottom: 1px solid var(--line); }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }

        .split { display: grid; gap: 2.5rem; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
        @media (max-width: 48rem) { .split { grid-template-columns: 1fr; } }

        dl.facts { margin: 0; }
        dl.facts dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin-top: .875rem; }
        dl.facts dd { margin: .125rem 0 0; }

        .copy { position: relative; }
        .copy pre { margin: .375rem 0; }

        .pager { display: flex; gap: .5rem; align-items: center; margin-top: 1.5rem; }

        footer.site {
            border-top: 1px solid var(--line);
            padding: 1.5rem 0;
            color: var(--muted);
            font-size: .875rem;
        }

        .empty { padding: 3rem 1rem; text-align: center; color: var(--muted); }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap">
            <a class="brand" href="<?= $view->e($view->url()) ?>">
                <span class="mark">B</span>
                <?= $view->e($view->name()) ?>
            </a>
            <nav class="site">
                <a href="<?= $view->e($view->url('browse')) ?>">Browse</a>
                <a href="<?= $view->e($view->url('docs')) ?>">Docs</a>
                <?php if (count($channels) > 1): ?>
                    <?php foreach ($channels as $each): ?>
                        <?php if ($each !== ($channel ?? '')): ?>
                            <a class="small" href="<?= $view->e($view->url('browse', ['channel' => $each])) ?>">
                                <?= $view->e($each) ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main>
        <div class="wrap">
            <?= $content ?>
        </div>
    </main>

    <footer class="site">
        <div class="wrap">
            <?php if ($view->config()->footer() !== ''): ?>
                <p><?= $view->e($view->config()->footer()) ?></p>
            <?php endif; ?>
            <p>
                A Botex archive.
                Install from it with <code>php bin/console ext:install &lt;slug&gt;</code>.
                <?php if (($channel ?? '') !== ''): ?>
                    Channel: <code><?= $view->e($channel) ?></code>.
                <?php endif; ?>
            </p>
        </div>
    </footer>
</body>
</html>
