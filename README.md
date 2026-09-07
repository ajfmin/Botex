# Botex

An advanced base for Telegram bots.

Botex is the part you would otherwise write again for every bot: routing,
conversations, buttons that still work tomorrow, background jobs, a wallet,
an admin panel, and an extension system that keeps your own code out of the
framework's way. Then it adds the part almost nobody writes — an archive you
can install and update extensions *and the core itself* from, without
discarding your changes.

```bash
php bin/console core:update
php bin/console ext:install Clock
```

## Why it exists

Most bot frameworks make the first day pleasant and the sixth month
expensive. The turning point is usually the same: you needed one behaviour
the framework did not have, you edited its source, and from then on every
upgrade was a merge.

Botex is built around avoiding that. Your code lives in `extensions/`, your
configuration in `config/` and `.env`, your data in `storage/` — and a core
update writes to none of them. It writes `src/`, `bootstrap/`, `public/`,
`bin/`, `docs/` and `composer.json`, and if you have edited one of the files
a release changes, it stops and tells you which rather than overwriting it.

## What you get

**Extensions.** A folder with a manifest and an `Extension` class. It
contributes commands, callbacks, middleware, conversations, admin sections,
jobs, and settings. Enable and disable without touching anything else.

**Routing that survives.** Commands, callback buttons, and multi-step
conversations, with a resolution order you can read rather than guess at.

**Run actions.** Buttons whose behaviour is stored server-side, so a button
pressed a week later does what it said, and a tampered callback does
nothing.

**Jobs.** Scheduled and repeating work with one leased worker, so two
crontabs cannot run the same job twice.

**A wallet.** Balances in minor units, credits, debits, refunds, and a
ledger.

**An admin panel.** Inside Telegram, plus a token-gated read-only web page.
Both are deliberately read-only about anything that writes code.

**An archive.** Publish extensions and core releases, browse them on a
website, install them by name.

## Requirements

PHP 8.2 or newer, with `curl`, `json`, `mbstring` and `openssl`. A database
that Eloquent supports. **No `zip` extension needed** — Botex reads and
writes packages itself.

## Getting started

```bash
composer install
cp .env.example .env      # then fill in BOT_TOKEN and the database
php bin/console migrate
php bin/console doctor     # checks the environment and says what is missing
```

Point Telegram's webhook at `public/index.php`, and run the worker if you
use jobs:

```bash
php bin/console jobs:work
```

## Using an archive

Set it in `config/config.php` — not `.env`, because which archive you trust
is a decision, not a deploy detail:

```php
'archive' => [
    'url' => 'https://archive.example.com',
    'channel' => 'stable',
    'public_key' => '',   // set this and unsigned packages are refused
],
```

```bash
php bin/console archive:ping
php bin/console ext:remote
php bin/console ext:show Clock
php bin/console ext:install Clock
```

Run your own with the `hub/` folder — copy it to a server, point a vhost at
`hub/public/`. It has no database and no composer dependencies.

```bash
php hub/bin/hub serve --port=8080
php hub/bin/hub publish extensions/Clock --changelog="First release."
```

## Updating safely

```bash
php bin/console core:check           # what is available, what you have edited
php bin/console core:update --dry-run
php bin/console core:update
php bin/console core:rollback        # if you change your mind
```

An update takes a backup first, refuses rather than overwriting your edits,
and refuses outright a package that would write outside the core or delete
most of it. Details in [docs/UPDATING.md](docs/UPDATING.md).

## Documentation

| | |
| --- | --- |
| [docs/EXTENSIONS.md](docs/EXTENSIONS.md) | writing extensions — the long one, with a worked example |
| [docs/UPDATING.md](docs/UPDATING.md) | the safety model, conflicts, rollback |
| [docs/ARCHIVE.md](docs/ARCHIVE.md) | the package format and hosting an archive |

## Checking your install

```bash
php bin/console doctor     # environment, extensions, legacy classes
php bin/console version    # version, edited core files, archive
php bin/selftest           # the archive and update machinery, offline
```

`selftest` needs no database and no network, which is deliberate: it runs on
a fresh host before `composer install` has necessarily worked, which is when
you most want to know whether the update machinery is sound.

## Upgrading from the pre-Botex namespace

The namespace is `Botex\`; it was `App\`. Extensions written against the old
one keep working — a compatibility shim aliases them on demand and logs a
notice naming the extension. `php bin/console doctor` lists anything still
relying on it. Update the imports when convenient; the shim is not meant to
be permanent.
