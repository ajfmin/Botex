<?php

/**
 * Browse and search results, sorted and paged.
 *
 * @var Hub\View $view
 * @var string $channel
 * @var string $query
 * @var string $sort
 * @var array<array<string,mixed>> $results
 * @var int $total
 * @var int $page
 * @var int $pages
 */

/** Keeps the current query and channel when only one parameter changes. */
$link = function (array $overrides) use ($view, $query, $sort, $channel): string {
    $params = array_filter([
        'q' => $query,
        'sort' => $sort === 'name' ? '' : $sort,
        'channel' => $channel === $view->config()->defaultChannel() ? '' : $channel,
    ], static fn (string $v): bool => $v !== '');

    return $view->url('browse', array_filter(
        array_merge($params, $overrides),
        static fn ($v): bool => $v !== '' && $v !== null
    ));
};

?>
<h1><?= $query === '' ? 'Extensions' : 'Search' ?></h1>

<form class="search" method="get" action="<?= $view->e($view->url()) ?>">
    <input type="hidden" name="p" value="browse">
    <?php if ($channel !== $view->config()->defaultChannel()): ?>
        <input type="hidden" name="channel" value="<?= $view->e($channel) ?>">
    <?php endif; ?>
    <?php if ($sort !== 'name'): ?>
        <input type="hidden" name="sort" value="<?= $view->e($sort) ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= $view->e($query) ?>"
           placeholder="Search extensions…" aria-label="Search extensions">
    <button class="button" type="submit">Search</button>
</form>

<p class="muted small">
    <?php if ($query !== ''): ?>
        <?= (int) $total ?> result<?= $total === 1 ? '' : 's' ?> for “<?= $view->e($query) ?>”.
        <a href="<?= $view->e($view->url('browse')) ?>">Clear</a>
        &middot;
    <?php else: ?>
        <?= (int) $total ?> extension<?= $total === 1 ? '' : 's' ?>.
    <?php endif; ?>

    Sort by
    <?php foreach (['name' => 'name', 'downloads' => 'installs', 'updated' => 'recently updated'] as $key => $label): ?>
        <?php if ($key === $sort): ?>
            <strong><?= $view->e($label) ?></strong>
        <?php else: ?>
            <a href="<?= $view->e($link(['sort' => $key, 'page' => ''])) ?>"><?= $view->e($label) ?></a>
        <?php endif; ?>
        <?= $key === 'updated' ? '' : '&middot;' ?>
    <?php endforeach; ?>
</p>

<?php if ($results === []): ?>
    <div class="empty">
        <p><?= $query === '' ? 'Nothing published on this channel yet.' : 'No extension matches that.' ?></p>
        <?php if ($query !== ''): ?>
            <p class="small"><a href="<?= $view->e($view->url('browse')) ?>">See everything</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="list">
        <?php foreach ($results as $package): ?>
            <div class="row">
                <div class="body">
                    <h3>
                        <a href="<?= $view->e($view->packageUrl((string) $package['slug'])) ?>">
                            <?= $view->e($package['name']) ?>
                        </a>
                        <span class="muted small"><?= $view->e($package['version']) ?></span>
                        <?php if ($package['signed'] ?? false): ?>
                            <span class="tag ok">signed</span>
                        <?php endif; ?>
                    </h3>
                    <p><?= $view->e($package['description']) ?></p>
                    <div class="meta">
                        <span><code><?= $view->e($package['slug']) ?></code></span>
                        <?php if ((int) ($package['downloads'] ?? 0) > 0): ?>
                            <span><?= (int) $package['downloads'] ?> installs</span>
                        <?php endif; ?>
                        <?php if (($package['published_at'] ?? '') !== ''): ?>
                            <span><?= $view->e($view->ago((string) $package['published_at'])) ?></span>
                        <?php endif; ?>
                        <?php foreach ((array) ($package['requires'] ?? []) as $what => $constraint): ?>
                            <span><?= $view->e($what) ?> <?= $view->e($constraint) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a class="button secondary" href="<?= $view->e($link(['page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>

            <span class="muted small">Page <?= (int) $page ?> of <?= (int) $pages ?></span>

            <?php if ($page < $pages): ?>
                <a class="button secondary" href="<?= $view->e($link(['page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
