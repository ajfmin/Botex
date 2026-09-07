<?php

/**
 * How to point a bot at this archive, and how to publish to it.
 *
 * @var Hub\View $view
 * @var string $channel
 */

$url = $view->config()->url();

?>
<h1>Using this archive</h1>

<h2>Point a bot at it</h2>

<p>Edit <code>config/config.php</code> in your Botex install:</p>

<pre><code>'archive' =&gt; [
    'url' =&gt; '<?= $view->e($url === '' ? 'https://your-archive.example' : $url) ?>',
    'channel' =&gt; '<?= $view->e($channel) ?>',
    'verify_tls' =&gt; true,
],</code></pre>

<p class="small muted">
    This lives in <code>config/config.php</code> rather than <code>.env</code>
    on purpose: an archive is allowed to ship executable code into your
    install, so the decision belongs in a file that gets read during review.
</p>

<h2>Find and install extensions</h2>

<pre><code>php bin/console ext:remote            # everything published here
php bin/console ext:search clock      # search
php bin/console ext:show Clock        # detail, versions, changelog
php bin/console ext:install Clock     # install the newest
php bin/console ext:install Clock --version=1.1.0
php bin/console ext:update Clock      # or --all
php bin/console ext:remove Clock</code></pre>

<p>
    Add <code>--dry-run</code> to any install or update to print the exact
    file-by-file plan without writing anything.
</p>

<h2>Update the core</h2>

<pre><code>php bin/console core:check            # is a newer release published
php bin/console core:diff             # core files you have edited
php bin/console core:update           # apply it
php bin/console core:rollback         # undo the last update</code></pre>

<h3>What an update will not do</h3>

<ul>
    <li>It never writes to <code>config/</code>, <code>.env</code>,
        <code>storage/</code>, <code>vendor/</code> or <code>extensions/</code>.</li>
    <li>Your extension settings and enabled flags live in
        <code>storage/</code>, so replacing an extension keeps every
        override an admin configured.</li>
    <li>If you have edited a core file the release also changes, the update
        <strong>stops</strong> and lists those files. Nothing is written
        until you revert them or pass <code>--force</code>, which backs the
        originals up to <code>storage/backups/</code> first.</li>
</ul>

<p class="small muted">
    Custom commands belong in an extension rather than in a patched core
    file: an extension lives in <code>extensions/</code>, which no core
    update touches, so it survives every upgrade without a conflict.
</p>

<h2>Publish to this archive</h2>

<p>From the machine that serves it:</p>

<pre><code>php hub/bin/hub publish /path/to/MyExtension --changelog="What changed."
php hub/bin/hub publish-core /path/to/botex
php hub/bin/hub list
php hub/bin/hub verify</code></pre>

<h3>Signing</h3>

<p>
    TLS proves which host you reached, and the checksum proves the bytes
    match what that host said to expect. Neither survives the archive itself
    being compromised. Signing moves that trust to a key that never has to
    sit on the server:
</p>

<pre><code>php hub/bin/hub keygen</code></pre>

<p>
    Set <code>signing_key</code> in <code>hub/config.php</code>, then paste
    the public key into each bot's <code>archive.public_key</code>. From
    then on a bot refuses any package that key did not sign.
</p>

<h2>API</h2>

<table>
    <thead>
        <tr><th scope="col">Endpoint</th><th scope="col">Returns</th></tr>
    </thead>
    <tbody>
        <tr><td><code>GET /api/v1/ping</code></td><td>archive name, channels, package count</td></tr>
        <tr><td><code>GET /api/v1/index</code></td><td>every package on a channel</td></tr>
        <tr><td><code>GET /api/v1/search?q=</code></td><td>search results</td></tr>
        <tr><td><code>GET /api/v1/package/&lt;slug&gt;</code></td><td>one package with its releases</td></tr>
        <tr><td><code>GET /api/v1/package/&lt;slug&gt;/&lt;version&gt;</code></td><td>one release, with its sha256</td></tr>
        <tr><td><code>GET /api/v1/download/&lt;slug&gt;/&lt;version&gt;</code></td><td>the <code>.botex</code> file</td></tr>
    </tbody>
</table>

<p class="small muted">
    Every endpoint takes an optional <code>?channel=</code>. A
    <code>.botex</code> file is an ordinary zip holding
    <code>botex.json</code> and a <code>files/</code> payload, so you can
    inspect one with any zip tool.
</p>
