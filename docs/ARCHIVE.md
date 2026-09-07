# The Archive

A Botex archive is a small website that publishes packages. Bots download
extensions and core releases from it; people browse it to find extensions,
read what they do, and copy the command that installs them.

It has no database, no composer dependencies and no framework. It is a
folder you copy onto a server, which is the point: an archive that is
tedious to host does not get hosted.

- [What a package is](#what-a-package-is)
- [Running your own archive](#running-your-own-archive)
- [Publishing](#publishing)
- [Signing](#signing)
- [The HTTP API](#the-http-api)
- [Pointing a bot at it](#pointing-a-bot-at-it)
- [Security](#security)

## What a package is

A `.botex` file. It is a zip, written and read by Botex's own pure-PHP
implementation, so neither end needs the `zip` extension.

```
Clock-1.3.0.botex
├── botex.json          the manifest
└── files/              the payload, exactly as it should land on disk
    ├── extension.json
    ├── Extension.php
    └── Command/Now.php
```

`botex.json` describes the release and, importantly, pins a sha256 for
**every** payload file:

```json
{
  "format": 1,
  "type": "extension",
  "slug": "Clock",
  "name": "Showing Clock",
  "version": "1.3.0",
  "description": "Shows the current time.",
  "requires": { "php": ">=8.2", "botex": "^1.0.0" },
  "changelog": "Adds per-zone buttons.",
  "readme": "# Showing Clock\n...",
  "files": {
    "extension.json": "9f2c…",
    "Extension.php": "4ab1…",
    "Command/Now.php": "77de…"
  }
}
```

Two rules make the `files` map worth having, and both are enforced when a
package is opened rather than when it is installed:

- every file in `files/` must appear in the map with a matching hash
- every entry in the map must exist in `files/`

The first stops a payload being edited after the fact. The second stops a
file being *added* to a package that the manifest never mentioned — the
case that would otherwise slip an extra PHP file into an install.

### Types

`type` is `extension` or `core`.

An **extension** package unpacks into `extensions/<slug>/`. The slug must
match the folder name and the `Extensions\<Slug>` namespace in its `entry`,
because the autoloader maps one onto the other. `hub publish` refuses a
mismatch rather than shipping something that cannot load.

A **core** package carries only the directories a core update is allowed to
write: `src/`, `bootstrap/`, `public/`, `bin/`, `docs/` and
`composer.json`. `config/`, `.env`, `storage/`, `extensions/` and `vendor/`
are never in it — which is why an update cannot touch them. See
[UPDATING.md](UPDATING.md).

## Running your own archive

The `hub/` folder is the whole archive. Copy it to a server, point a
vhost at `hub/public/`, and make `hub/storage/` writable.

```bash
scp -r hub/ you@server:/var/www/botex-archive
ssh you@server 'chmod -R u+w /var/www/botex-archive/storage'
```

Nginx:

```nginx
server {
    listen 443 ssl;
    server_name archive.example.com;

    root /var/www/botex-archive/public;
    index index.php;

    # Everything is routed through index.php.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

Apache with `mod_php` needs no more than a `DocumentRoot` pointing at
`hub/public/` and `AllowOverride All`.

To try it locally:

```bash
php hub/bin/hub serve --port=8080
```

### Configuration

`hub/config.php`, edited by hand. It is a PHP file returning an array, not
a `.env`: an archive is configured once by whoever installs it.

```php
return [
    'name' => 'Botex Archive',
    'url' => 'https://archive.example.com',
    'channels' => ['stable', 'beta'],
    'default_channel' => 'stable',

    // Signing. Empty means packages are published unsigned.
    'signing_key' => '',
    'signing_key_passphrase' => '',

    // Off by default. Only enable if you want HTTP publishing.
    'upload_token' => '',
];
```

**Channels** let you publish a release for testing without offering it to
every bot. A bot reads one channel, set by `archive.channel` in its own
config. `stable` is the default at both ends.

## Publishing

From the machine that holds the code:

```bash
# An extension. The folder name must match its entry namespace.
php hub/bin/hub publish extensions/Clock --changelog="Adds per-zone buttons."

# A core release. Bump Botex::VERSION in src/Botex.php first.
php hub/bin/hub publish-core . --changelog="Fixes the update planner."

# To a channel other than the default.
php hub/bin/hub publish extensions/Clock --channel=beta
```

Each build is verified before it is stored: the publisher re-opens the
package it just wrote and checks every hash. That looks redundant — it was
built from known files a moment earlier — but it means a bug in the writer
is caught by the one person who can fix it, rather than by every bot that
downloads the result.

Publishing the same version twice is refused. Bump the version, or pass
`--overwrite` if you are certain.

| Command | Effect |
| --- | --- |
| `publish <path>` | publishes an extension folder |
| `publish-core <path>` | publishes a core release from a Botex checkout |
| `list [--channel=]` | what is published |
| `show <slug>` | one package with its version history |
| `unpublish <slug> <version>` | removes one release and reindexes |
| `reindex` | rebuilds `index.json` from the stored packages |
| `verify [<slug>] [<version>]` | re-checks stored packages against their manifests |
| `keygen` | generates a signing keypair |
| `sync` | rebuilds the index and prunes orphaned files |
| `serve [--port=]` | a local dev server |

Run `verify` after moving an archive between servers. It re-opens every
stored package, so it catches a truncated copy before a bot does.

## Signing

Optional, and worth it if bots outside your control install from you. A
signature is what protects against the archive itself being compromised —
a hash the same server publishes does not.

```bash
php hub/bin/hub keygen
```

That writes `hub/storage/keys/private.pem` and `public.pem`, and refuses to
overwrite an existing key. Set `signing_key` in `hub/config.php` to the
private key path; everything published from then on is signed.

Give the **public** key to whoever runs a bot. They set it in their
`config/config.php`:

```php
'archive' => [
    'url' => 'https://archive.example.com',
    'public_key' => __DIR__ . '/archive-public.pem',
],
```

Once a bot has a public key configured, an unsigned package is **refused**,
not merely warned about. That is the only behaviour worth having: a
signature that can be omitted by an attacker who controls the response
protects nothing.

Keep the private key off the web root and out of version control. If it
leaks, generate a new pair, re-publish, and distribute the new public key —
there is no revocation list.

## The HTTP API

Read-only, versioned so an archive can serve an older bot.

| Endpoint | Returns |
| --- | --- |
| `GET /api/v1/index?channel=stable` | every package with its newest version |
| `GET /api/v1/package/<slug>?channel=` | one package, with version history |
| `GET /api/v1/package/<slug>/<version>` | one release, including its sha256 |
| `GET /api/v1/search?q=&channel=` | matches on slug, name, description, keywords |
| `GET /api/v1/download/<slug>/<version>` | the `.botex` bytes |
| `GET /api/v1/download/<slug>/<version>/signature` | the detached signature |
| `GET /api/v1/ping` | name, channels, package count, whether it signs |

There is one write endpoint, `POST /api/v1/publish`, and it is **disabled
unless `upload_token` is set**. Leave it empty and the archive has no write
surface at all — publish over SSH instead. If you do enable it, the token
goes in an `Authorization: Bearer` header and the archive must be HTTPS.

### The website

The same data, rendered for people:

| Path | Page |
| --- | --- |
| `/` | recently updated packages |
| `/browse` | everything, searchable |
| `/package/<slug>` | description, readme, versions, changelog, requirements, install commands |
| `/docs` | how to install and publish |

The package page leads with the commands, because that is what a visitor
came for:

```
php bin/console ext:install Clock
php bin/console ext:update Clock
php bin/console ext:remove Clock
```

Readmes are untrusted input. They are escaped first and then a small subset
of Markdown is re-tagged, so a package author cannot inject HTML into the
archive's own pages.

## Pointing a bot at it

In `config/config.php` — deliberately **not** `.env`, because this is a
choice the operator makes, not a per-deploy secret:

```php
'archive' => [
    'url' => 'https://archive.example.com',
    'channel' => 'stable',
    'verify_tls' => true,
    'timeout' => 20,
    'public_key' => '',
    'cache_ttl' => 3600,
],
```

Then:

```bash
php bin/console archive:ping      # is it reachable, does it sign
php bin/console ext:remote        # what it offers
php bin/console ext:search feed
php bin/console ext:show Clock
```

HTTPS is required. `http://` is refused unless the host is `localhost` or
`127.0.0.1`, so a local hub is easy to test against and a plaintext
production archive is not something you can configure by accident.

`cache_ttl` is why the admin panel can say "1 update available" without a
webhook ever waiting on your archive: browsing answers are cached on disk.
Downloads never read that cache — they re-resolve the version first, so an
install is never based on a stale index.

## Security

What an archive is trusted with, and what it is not.

**The bot verifies four things independently on every download:**

1. the file's sha256 against what the index published — a truncated or
   substituted response
2. the signature, when a public key is configured — a compromised archive
3. every payload file against the manifest — a repacked payload
4. the slug and version against what was asked for — being handed a
   different package than the one requested

The fourth is the easiest to leave out and matters: without it, asking for
`Clock@1.3.0` and receiving a package calling itself something else would
install under the name it claims.

**Traversal is refused at both ends.** A path with `..`, an absolute path, a
drive letter or a NUL byte is rejected when a package is written, when it is
parsed, and again when a file is about to be written. Three checks for one
rule, because the consequence of missing it is a file outside the install
directory.

**Zip bombs are bounded.** An entry's declared size is checked before
inflating and its real size and CRC after, against a per-entry and a total
cap.

**The archive is never trusted to say where a file goes.** A core package
may only write inside the tracked directories; anything else in it is
reported as blocked and the update refuses to proceed. An archive you do not
control cannot use a package to reach your `config/` or your `.env`.

**Installing is a CLI action, always.** Neither the admin panel nor
`panel.php` will download or write a PHP file, no matter who is logged in.
An admin is whoever holds a Telegram account; a button that installed code
would turn a stolen session into remote code execution.

See [UPDATING.md](UPDATING.md) for what happens on the bot's side, and
[EXTENSIONS.md](EXTENSIONS.md) for writing something worth publishing.
