<?php

/**
 * Home: search, the core release, newest and most-installed extensions.
 *
 * @var Hub\View $view
 * @var string $channel
 * @var array<string,mixed>|null $core
 * @var int $total
 * @var array<string,array<string,mixed>> $featured
 * @var array<string,array<string,mixed>> $recent
 */

?>
<h1><?= $view->e($view->name()) ?></h1>
<p class="muted"><?= $view->e($view->config()->tagline()) ?></p>

<form class="search" method="get" action="<?= $view->e($view->url()) ?>">
    <input type="hidden" name="p" value="browse">
    <?php if ($channel !== $view->config()->defaultChannel()): ?>
        <input type="hidden" name="channel" value="<?= $view->e($channel) ?>">
    <?php endif; ?>
    <input type="search" name="q" placeholder="Search <?= (int) $total ?> extensions…" aria-label="Search extensions">
    <button class="button" type="submit">Search</button>
</form>

<?php if ($total === 0 && $core === null): ?>
    <div class="empty">
        <p>Nothing is published yet.</p>
        <p class="small">
            Publish an extension with<br>
            <code>php hub/bin/hub publish /path/to/Extension</code>
        </p>
    </div>
<?php endif; ?>

<?php if ($core !== null): ?>
    <h2>Botex core</h2>
    <div class="card">
        <h3>
            <a href="<?= $view->e($view->packageUrl('core')) ?>">Botex <?= $view->e($core['version']) ?></a>
            <span class="tag core">core</span>
            <?php if ($core['signed'] ?? false): ?><span class="tag ok">signed</span><?php endif; ?>
        </h3>
        <p><?= $view->e($core['description'] ?: 'The Botex core.') ?></p>
        <div class="copy">
            <pre><code><?= $view->e($core['commands']['update'] ?? 'php bin/console core:update') ?></code></pre>
        </div>
        <p class="small muted">
            Published <?= $view->e($view->ago((string) $core['published_at'])) ?>.
            A core update never touches <code>config/</code>, <code>storage/</code> or your extensions,
            and stops if you have edited a file it would replace.
        </p>
    </div>
<?php endif; ?>

<?php if ($featured !== []): ?>
    <h2>Most installed</h2>
    <div class="cards">
        <?php foreach ($featured as $package): ?>
            <div class="card">
                <h3>
                    <a href="<?= $view->e($view->packageUrl((string) $package['slug'])) ?>">
                        <?= $view->e($package['name']) ?>
                    </a>
                </h3>
                <p><?= $view->e($package['description']) ?></p>
                <div class="meta">
                    <span><?= $view->e($package['version']) ?></span>
                    <?php if ((int) ($package['downloads'] ?? 0) > 0): ?>
                        <span><?= (int) $package['downloads'] ?> installs</span>
                    <?php endif; ?>
                    <?php if ($package['signed'] ?? false): ?><span class="tag ok">signed</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <h2>Recently updated</h2>
    <div class="list">
        <?php foreach ($recent as $package): ?>
            <div class="row">
                <div class="body">
                    <h3>
                        <a href="<?= $view->e($view->packageUrl((string) $package['slug'])) ?>">
                            <?= $view->e($package['name']) ?>
                        </a>
                        <span class="muted small"><?= $view->e($package['version']) ?></span>
                    </h3>
                    <p><?= $view->e($package['description']) ?></p>
                    <div class="meta">
                        <span><?= $view->e($view->ago((string) $package['published_at'])) ?></span>
                        <span><code><?= $view->e($package['slug']) ?></code></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p style="margin-top:1.5rem">
        <a class="button secondary" href="<?= $view->e($view->url('browse')) ?>">Browse all <?= (int) $total ?></a>
    </p>
<?php endif; ?>
