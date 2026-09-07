<?php

/**
 * One package: what it is, how to install it, and every release.
 *
 * The commands are the point of this page -- they are what someone came
 * here to copy -- so they sit above the readme rather than below it.
 *
 * @var Hub\View $view
 * @var string $channel
 * @var array<string,mixed> $package
 * @var string|null $selected a version being inspected, or null for current
 */

$isCore = ($package['type'] ?? '') === 'core';
$releases = (array) ($package['releases'] ?? []);

// The release whose detail is shown. Defaults to current; a bad version in
// the URL falls back rather than erroring, since this is a browsing page.
$current = $releases[0] ?? [];
$showing = $current;

if ($selected !== null) {
    foreach ($releases as $release) {
        if (($release['version'] ?? '') === $selected) {
            $showing = $release;
            break;
        }
    }
}

$version = (string) ($showing['version'] ?? $package['version'] ?? '');
$isCurrent = $version === (string) ($package['version'] ?? '');

$commands = (array) ($package['commands'] ?? []);

// Installing a version other than the newest needs the flag spelled out,
// so the command shown always matches the version being viewed.
$install = (string) ($commands['install'] ?? '');

if (!$isCurrent && $install !== '' && !$isCore) {
    $install .= ' --version=' . $version;
}

?>
<p class="small muted">
    <a href="<?= $view->e($view->url('browse')) ?>">Extensions</a>
    /
    <code><?= $view->e($package['slug']) ?></code>
</p>

<h1>
    <?= $view->e($package['name']) ?>
    <span class="muted"><?= $view->e($version) ?></span>
</h1>

<p class="muted"><?= $view->e($package['description']) ?></p>

<div class="meta" style="margin-bottom:1.5rem">
    <?php if ($isCore): ?><span class="tag core">core</span><?php endif; ?>
    <?php if ($showing['signed'] ?? false): ?><span class="tag ok">signed</span><?php endif; ?>
    <?php if (!$isCurrent): ?>
        <span class="tag">older release</span>
    <?php endif; ?>
    <span><?= $view->e($channel) ?></span>
    <?php if ((int) ($package['downloads'] ?? 0) > 0): ?>
        <span><?= (int) $package['downloads'] ?> installs</span>
    <?php endif; ?>
</div>

<?php if (!$isCurrent): ?>
    <p class="small">
        You are looking at <?= $view->e($version) ?>.
        The current release is
        <a href="<?= $view->e($view->packageUrl((string) $package['slug'])) ?>">
            <?= $view->e($package['version']) ?></a>.
    </p>
<?php endif; ?>

<div class="split">
    <div>
        <h2>Install</h2>

        <?php if ($isCore): ?>
            <p class="small muted">
                Updating the core replaces the files under
                <code>src/</code>, <code>bootstrap/</code>, <code>public/</code>,
                <code>bin/</code> and <code>docs/</code>.
                It never writes to <code>config/</code>, <code>.env</code>,
                <code>storage/</code> or <code>extensions/</code>, and it stops
                with a list if you have edited any file it would replace.
            </p>
        <?php endif; ?>

        <div class="copy">
            <?php if ($install !== ''): ?>
                <p class="small muted" style="margin-bottom:0">
                    <?= $isCore ? 'Update the core' : 'Install it' ?>
                </p>
                <pre><code><?= $view->e($install) ?></code></pre>
            <?php endif; ?>

            <?php if (($commands['update'] ?? '') !== '' && !$isCore): ?>
                <p class="small muted" style="margin-bottom:0">Update it later</p>
                <pre><code><?= $view->e($commands['update']) ?></code></pre>
            <?php endif; ?>

            <?php if (($commands['remove'] ?? '') !== ''): ?>
                <p class="small muted" style="margin-bottom:0">Remove it</p>
                <pre><code><?= $view->e($commands['remove']) ?></code></pre>
            <?php endif; ?>
        </div>

        <p class="small muted">
            Add <code>--dry-run</code> to any of these to see exactly which files
            would change before anything is written.
        </p>

        <?php if (($package['readme'] ?? '') !== ''): ?>
            <h2>Readme</h2>
            <?= $view->markdown((string) $package['readme']) ?>
        <?php endif; ?>

        <h2>Releases</h2>
        <table>
            <thead>
                <tr>
                    <th scope="col">Version</th>
                    <th scope="col">Published</th>
                    <th scope="col">Size</th>
                    <th scope="col">Files</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($releases as $release): ?>
                    <tr>
                        <td>
                            <a href="<?= $view->e($view->packageUrl((string) $package['slug'], (string) $release['version'])) ?>">
                                <?= $view->e($release['version']) ?>
                            </a>
                            <?php if (($release['version'] ?? '') === ($package['version'] ?? '')): ?>
                                <span class="tag ok">current</span>
                            <?php endif; ?>
                            <?php if (($release['changelog'] ?? '') !== ''): ?>
                                <div class="small muted"><?= $view->e($release['changelog']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $view->e($view->date((string) ($release['published_at'] ?? ''))) ?></td>
                        <td class="small"><?= $view->e($view->size((int) ($release['size'] ?? 0))) ?></td>
                        <td class="small"><?= (int) ($release['files'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <aside>
        <dl class="facts">
            <dt>Slug</dt>
            <dd><code><?= $view->e($package['slug']) ?></code></dd>

            <dt>Current version</dt>
            <dd><?= $view->e($package['version']) ?></dd>

            <?php if (($showing['published_at'] ?? '') !== ''): ?>
                <dt>Published</dt>
                <dd class="small"><?= $view->e($view->ago((string) $showing['published_at'])) ?></dd>
            <?php endif; ?>

            <?php foreach ((array) ($showing['requires'] ?? $package['requires'] ?? []) as $what => $constraint): ?>
                <dt>Requires</dt>
                <dd><?= $view->e($what) ?> <code><?= $view->e($constraint) ?></code></dd>
            <?php endforeach; ?>

            <?php if (($package['author'] ?? '') !== ''): ?>
                <dt>Author</dt>
                <dd><?= $view->e($package['author']) ?></dd>
            <?php endif; ?>

            <?php if (($package['homepage'] ?? '') !== ''): ?>
                <dt>Homepage</dt>
                <dd class="small">
                    <?php
                    // Only http(s) is linked: a javascript: or data: URL in
                    // package metadata would otherwise become a clickable
                    // script on this page.
                    $homepage = (string) $package['homepage'];
                    $scheme = strtolower((string) parse_url($homepage, PHP_URL_SCHEME));
                    ?>
                    <?php if (in_array($scheme, ['http', 'https'], true)): ?>
                        <a href="<?= $view->e($homepage) ?>" rel="nofollow noopener">
                            <?= $view->e(parse_url($homepage, PHP_URL_HOST) ?: $homepage) ?>
                        </a>
                    <?php else: ?>
                        <?= $view->e($homepage) ?>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>

            <?php if (($showing['sha256'] ?? '') !== ''): ?>
                <dt>sha256</dt>
                <dd class="small" style="word-break:break-all"><code><?= $view->e($showing['sha256']) ?></code></dd>
            <?php endif; ?>

            <dt>Download</dt>
            <dd class="small">
                <a href="<?= $view->e($view->downloadUrl((string) $package['slug'], $version)) ?>">
                    <?= $view->e(Botex\Archive\Package::filename((string) $package['slug'], $version)) ?>
                </a>
            </dd>
        </dl>

        <?php if (($package['keywords'] ?? []) !== []): ?>
            <dl class="facts">
                <dt>Keywords</dt>
                <dd>
                    <?php foreach ((array) $package['keywords'] as $keyword): ?>
                        <a class="tag" href="<?= $view->e($view->url('browse', ['q' => (string) $keyword])) ?>">
                            <?= $view->e($keyword) ?>
                        </a>
                    <?php endforeach; ?>
                </dd>
            </dl>
        <?php endif; ?>
    </aside>
</div>
