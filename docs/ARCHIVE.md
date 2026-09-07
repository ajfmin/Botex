# The Archive

A Botex archive — the hub — is a small website that publishes packages. Bots
download extensions and core releases from it; people browse it to find
extensions, read what they do, and copy the command that installs them.

It is also the only thing a bot talks to. The core originates on GitHub, and
the hub is what follows it: `mirror-core` reads the repository's releases,
repackages each one and publishes it here. One machine spends the GitHub rate
limit and holds any token, and bots see a static file with a published hash.

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

A core package is always published under the slug `core`, whether it was built
from a local checkout or mirrored from a GitHub release.

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

    // Which repository the core is mirrored from. See below.
    'core' => ['repository' => 'ajfmin/Botex'],
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

## Mirroring the core from GitHub

The core is developed on GitHub and served from here. The hub is what bridges
the two:

```bash
php hub/bin/hub releases          # what GitHub has, and what is already here
php hub/bin/hub mirror-core       # the newest release
php hub/bin/hub mirror-core --version=1.2.0
php hub/bin/hub mirror-core --all # the whole history; skips what is here
```

Point it at a repository in `hub/config.php`:

```php
'core' => [
    'repository' => 'ajfmin/Botex',
    'prereleases' => false,
    'token' => '',          // only for a private repository
],
```

Only tags that look like versions are considered. Drafts are skipped,
prereleases are skipped unless you opt in, and `v1.2.0` is understood as
version `1.2.0` — the tag and the version are tracked separately, because
addressing a download with the wrong one asks for a ref that does not exist.

### What gets mirrored

Each release is taken in whichever of these forms it offers:

1. **An attached `.botex`**, built by `publish-core`. Republished as-is, so the
   per-file hashes are the ones the maintainer built. Verified in full before
   anything is stored — the hub is about to sign it, and signing bytes nobody
   opened would mean vouching for them.
2. **The source archive** GitHub generates for every tag. Unpacked here,
   filtered to the tracked directories, and built into a package. The hashes
   are then computed by whoever ran the mirror rather than fixed when the
   release was cut, which is the only difference.

Either way the release must be internally consistent: if the tag says `1.2.0`
and the `src/Botex.php` inside declares something else, mirroring refuses,
because publishing it would leave every bot that installs it reporting the
wrong version and being offered the same update forever.

`releases` marks which form each release will use, and whether it is already
published here.

### Keeping it current

`mirror-core --all` skips versions already published, so it is safe on a
timer — one API call, then downloads only what is new:

```cron
17 4 * * * cd /var/www/botex-archive && php bin/hub mirror-core --all
```

It exits non-zero if a release failed, so a broken tag is reported rather than
mirroring nothing quietly for weeks. One bad release does not stop the others.

### Cutting a release by hand

Publishing straight from a checkout still works, and skips GitHub entirely:

```bash
# Bump Botex::VERSION in src/Botex.php first.
php hub/bin/hub publish-core . --changelog="Fixes the update planner."
```

Useful for a private fork, or for testing a release before tagging it.

| Command | Effect |
| --- | --- |
| `publish <path>` | publishes an extension folder |
| `publish-core <path>` | publishes a core release from a Botex checkout |
| `mirror-core [--version=] [--all]` | publishes core releases from GitHub |
| `releases` | GitHub's releases, and which are mirrored |
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

That block is the bot's entire view of the world. Extensions and the core both
come through it, and there is nothing else to configure — no repository, no
token. `php bin/console version` prints the archive it is pointed at.

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

**No upstream is trusted to say where a file goes.** A core package may only
write inside the tracked directories; anything else in it is reported as
blocked and the update refuses to proceed. No archive can use a package to
reach your `config/` or your `.env`, and no local edit of yours is overwritten
without `--force`.

**The hub is one upstream for two things, and that is a real cost.** Because
the core comes through it, a hub you do not control is a hub that can offer
you a core. Set `public_key` and the bot will refuse anything not signed by
the key you pinned, which is what makes a compromised host insufficient on its
own. The alternative — bots reading GitHub directly — trades this for every
bot depending on `api.github.com`, a shared rate limit per IP, and a token on
every deploy for a private repository. One machine holding the key and
spending the rate limit is the trade being made.

**The hub does not forward what it has not checked.** A release fetched from
GitHub is parsed and fully verified before it is stored, then signed with the
hub's own key. It refuses a release whose tag and declared version disagree.
Publishing bytes unopened would mean vouching for them.

**Installing is a CLI action, always.** Neither the admin panel nor
`panel.php` will download or write a PHP file, no matter who is logged in.
An admin is whoever holds a Telegram account; a button that installed code
would turn a stolen session into remote code execution.

See [UPDATING.md](UPDATING.md) for what happens on the bot's side, and
[EXTENSIONS.md](EXTENSIONS.md) for writing something worth publishing.
