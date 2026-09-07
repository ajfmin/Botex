<?php

/**
 * Shown when something unexpected fails.
 *
 * Deliberately says nothing about what: the detail went to the error log,
 * and a stack trace or a filesystem path on a public page is an information
 * leak.
 *
 * @var Hub\View $view
 */

?>
<h1>Something went wrong</h1>

<p class="muted">
    The archive hit an internal error. It has been logged.
</p>

<p>
    <a class="button" href="<?= $view->e($view->url()) ?>">Back to the archive</a>
</p>
