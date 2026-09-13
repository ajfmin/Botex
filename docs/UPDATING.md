# Updating

Botex updates itself and its extensions without discarding your changes.
This describes how that promise is kept, where it stops, and what to do
when an update refuses to run.

Everything comes from one place: a Botex [hub](ARCHIVE.md), set as
`archive.url`. Extensions and the core are both ordinary packages there, so a
bot has exactly one upstream to configure, one host to trust and one thing to
be reachable.

The core originates on GitHub, but the bot never goes there. The hub mirrors
the repository's releases — `hub mirror-core` fetches each one, repackages it
as a verified `.botex` and publishes it — and bots install it like any other
package.

That indirection is the point. A bot on shared hosting is the worst place to
depend on api.github.com: rate limits are per-IP and shared with every other
tenant, a private repository would need its token copied onto every bot, and
`core:update` would break for everyone the day GitHub is unreachable. Mirrored,
it happens once, on one machine, under the hub operator's control — and what
bots download is a static file with a published hash.

- [The short version](#the-short-version)
- [What is yours and what is ours](#what-is-yours-and-what-is-ours)
- [Updating extensions](#updating-extensions)
- [Updating the core](#updating-the-core)
- [Conflicts](#conflicts)
- [Backups and rollback](#backups-and-rollback)
- [What updates refuse to do](#what-updates-refuse-to-do)
- [Command reference](#command-reference)

## The short version

```bash
php bin/console core:check          # anything new?
php bin/console core:update         # apply it, or refuse and tell you why
php bin/console core:rollback       # put it back
```

```bash
php bin/console ext:outdated
php bin/console ext:update --all
```

Everything is dry-runnable. `--dry-run` prints the plan and writes nothing:

```bash
php bin/console core:update --dry-run
```

## What is yours and what is ours

A core update writes inside these, and nowhere else:

```
src/  bootstrap/  public/  bin/  docs/  composer.json
```

It never writes any of these:

```
config/      your configuration
.env         your secrets
storage/     your data, logs, settings, backups
extensions/  your extensions, including ones you wrote
vendor/      composer's
```

This is not a convention, it is a gate. Every path in a package is checked
against that list twice — once when the update is planned, and again at the
moment before each file is written. A package containing
`config/config.php` does not overwrite your config; the path is reported as
**blocked** and the whole update refuses to run.

The list lives in one place, `Botex\Update\Inventory::TRACKED`, and the hub
stages exactly the same directories when it builds a core package — whether
from a checkout or from a mirrored GitHub release. `bin/selftest` checks that
the two agree, because a drift between them would mean shipping files the bot
then refuses to write.

It is also why mirroring a GitHub source archive is safe even though the
repository contains far more than the core. `config/config.php`,
`.env.example`, `hub/`, `.github/` and the rest are dropped while the package
is being built, on the hub, before anything is published. They never reach a
bot at all, so there is nothing for the planner to block.

### Why extension settings survive

Because they were never in `extensions/<Slug>/` to begin with. Enabled state
and settings overrides live in `storage/`, so replacing an extension's files
cannot touch them. An update restores the enabled state it found, and only
enables an extension on a **first** install.

### Ownership is by manifest, not by directory

Being inside `src/` is what makes a path *writable* by an update. It is not
what makes it **ours**. Those are two different questions, and the second is
answered by two lists:

```
in storage/inventory.json  (what this install was shipped)   -> core-owned
in the new release         (what the next version ships)     -> core-owned
in neither, but on disk                                      -> yours
```

So a file you add under a core directory — `src/Bot/Command/Profile.php` —
is yours. An update will not replace it, will not delete it, and will not
count it when deciding whether anything conflicts. The folder it sits in
changes none of that.

Both lists already exist, which is the point: `storage/inventory.json` is
written from the package every time one is applied, and the incoming
package carries its own `botex.json` file map. Nothing new is persisted,
and nothing is added to the writable surface to make this work — a file
placed there to carry ownership would be refused as an unknown path by
every bot still running an older core, locking them out of updating.

Keep the two questions apart:

```
writable surface  →  where may a package write at all?
ownership         →  which paths inside it are actually ours?
```

`src/Bot/Command/Profile.php` is inside the surface and still yours.

Deletion follows the same rule, in one direction only:

```
was in the old manifest AND is gone from the new one  -> deleted
absent from the new release, but never ours           -> left alone
```

### When a release wants a path you already used

If you wrote `src/Bot/Command/Stats.php` and a later Botex ships its own,
the update **stops**:

```
Core update conflict:

  src/Bot/Command/Stats.php

A user-owned file already exists at a path introduced by the new Botex version.

Rename or move the custom file before updating.
```

Nothing is written, and `--force` does not apply. Forcing means "discard my
edit to a core file"; there is no core version of this file to fall back to,
so the only thing forcing could do is delete something that was never the
core's. Renaming your file — or moving it into an extension — is the fix.

### Where to put custom behaviour

**Small, bot-specific commands** go in `src/Bot/Command/`. Drop in a class
implementing `CommandInterface` and it is discovered and registered: no
manifest, no extension class, and the updater leaves it alone. See
[EXTENSIONS.md](EXTENSIONS.md).

**Anything modular or installable** — its own tables, settings, admin
screens, something you would ship to another bot — belongs in `extensions/`,
which a core update never looks at.

What still conflicts is **editing a file the core shipped**. That is a real
conflict and always will be: the release changed the same file you did.

## Updating extensions

```bash
php bin/console ext:outdated              # installed vs archive
php bin/console ext:update Clock
php bin/console ext:update --all
php bin/console ext:update Clock --version=1.2.0
```

Files are unpacked to a staging directory, every one verified against the
manifest, and only then swapped in by renaming directories — the old one
aside, the new one in, the old one deleted. If anything fails partway, the
original is renamed back. There is no window in which the extension is half
replaced.

The extension's `install()` runs again after an update, so a new release can
add a table or a setting. A failure there is logged but does not fail the
update: the files are already correct, and refusing at that point would
leave a working install reported as broken.

With `--all`, one extension failing does not abandon the rest. Each is
reported and the exit code is non-zero if any failed.

## Updating the core

```bash
php bin/console core:check
php bin/console core:update --dry-run
php bin/console core:update
php bin/console core:update --version=1.2.0
```

The only setting involved is the one you already have:

```php
'archive' => [
    'url' => 'https://archive.example.com',
    'channel' => 'stable',
],
```

The core is published on that hub as a package with the slug `core`, so
`core:check` is asking the hub what it serves — one HTTP request to a host you
chose, answered from a static file. Whether the hub mirrors GitHub, and how
often, is the hub operator's business and invisible here.

If a hub serves no core, `core:check` says so and nothing breaks: extensions
keep working. That is a normal setup for a hub that only publishes extensions.

What happens, in order:

1. **Compare.** Every file in the package is hashed against what is on disk
   and against the baseline recorded at install time. That three-way
   comparison is what distinguishes "upstream changed this" from "you
   changed this".
2. **Refuse, or plan.** A file you edited that this release also changes is
   a conflict, and conflicts stop the update before anything is written.
3. **Back up.** Every file about to change or be deleted is copied to
   `storage/backups/<timestamp>/`.
4. **Verify the staged files.** Each is re-hashed after unpacking.
5. **Write.** Then record the new baseline.

If step 5 fails partway, the files already written are restored from the
backup. If the restore also fails — a disk filling up, permissions
changing — the update says so and names the backup directory, because a
half-written core is the one case where a human has to look.

### The baseline

`storage/inventory.json` holds a sha256 for every core file as installed.
It is what makes "your changes" a knowable thing rather than a guess.

```bash
php bin/console core:diff        # what you have changed
php bin/console core:adopt       # accept the tree as the new baseline
```

If there is no baseline — a bot installed before this existed — local
changes cannot be detected. That is reported as *unknown*, never as clean:

```
There is no record of what was installed, so local changes
cannot be detected. Run: php bin/console core:adopt
```

Run `core:adopt` when you are satisfied the tree is what you want. It
records what is there now, which means it also adopts any edits you have
made — that is the point, but it is why it is a separate, deliberate step
rather than something an update does for you.

## Conflicts

A conflict is one file that **you edited** and **this release also
changes**. Applying it would discard your edit, so it does not apply:

```
Refusing to update the core: you have edited 1 file this release also changes.

  ! docs/EXTENSIONS.md

Your options:
  core:diff                    see exactly what you changed
  revert those files, then core:update
  core:update --force          apply anyway; the originals go to storage/backups/

Custom commands and settings belong in an extension, which no core update touches.
```

Nothing was written. The whole update is refused, not the conflicting file —
a partially applied core is worse than an unapplied one, because the version
number would then describe code that is not there.

Three ways forward:

- **Revert your edits**, then update. Cleanest, when the edit was a debug
  line you forgot about.
- **Move the behaviour into an extension**, then revert and update. The
  right answer for anything you want to keep.
- **`--force`**. Your version goes to `storage/backups/` and upstream's is
  written. Use it when you know the edit is disposable.

If you edited a file this release does *not* touch, there is no conflict and
the update proceeds — your edit stays exactly as it is.

A local edit that happens to match what upstream now ships is not a conflict
either. There is nothing to lose, so it is treated as already up to date and
re-baselined.

## Backups and rollback

Every update takes one first.

```bash
php bin/console core:backups
php bin/console core:rollback                        # the newest
php bin/console core:rollback 20260907-094412-c917   # a specific one
```

```
BACKUP                 FILES   REASON
20260907-094837-7cc1   2       core 1.0.1 -> 1.0.2
20260907-094412-c917   1       core 1.0.0 -> 1.0.1
20260907-083432-37a1   12      install Clock 1.3.0
```

A backup holds only the files that update changed, which is why it is small.
Rolling back restores them and rebuilds the baseline from what is then on
disk, so `core:diff` is meaningful immediately afterwards.

Backups live in `storage/backups/`, which is outside every tracked path — an
update cannot delete the thing that would undo it. Old ones are pruned; the
most recent are kept.

Restart the job worker after any core update or rollback, or it keeps
running the code it loaded at start.

## What updates refuse to do

Each of these is a refusal that fires without asking, and most are not
overridable. `--force` means "discard my edits" and nothing more.

**Write outside the tracked directories.** Reported as blocked. Not
forceable — a package trying it is malformed or hostile, and neither becomes
acceptable because you passed a flag.

**Delete most of the core.** A release that would remove the bulk of the
tree is refused as incomplete:

```
this package would delete 137 of 140 core files, which no real release
does. It looks incomplete rather than new, so it is refused
```

Not forceable. Someone published a fragment as a full release; the fix is to
publish a complete one. A core update rewrites the code performing the
update, so there is no chance to notice this afterwards.

**Install a package that fails verification.** Four independent checks, any
of which refuses: published hash, signature, manifest against payload, and
slug and version against what was requested.

**Install an unsigned package when a public key is configured.** Configuring
a key means signatures are mandatory. An optional signature protects
nothing. This covers the core too: a mirrored release is signed with the
hub's key like anything else it publishes, so the same check applies.

**Run on a PHP version the release requires more than.** Reported as an
unmet requirement, not forceable.

**Install anything from the web.** `panel.php` and the `/admin` panel show
what is available and print the command. Neither downloads or writes a file.
An admin is whoever holds a Telegram account, and a stolen session must not
become remote code execution.

## Command reference

### Core

| Command | Effect |
| --- | --- |
| `core:check [--fresh]` | installed vs what the hub serves, and whether anything is edited |
| `core:update [--version=] [--dry-run] [--force]` | applies a release, or refuses and says why |
| `core:diff [--verbose]` | core files you have changed |
| `core:adopt` | re-records the files it already owns; adopts everything only on a fresh install |
| `core:backups` | what can be rolled back to |
| `core:rollback [<backup>]` | restores one; the newest by default |

### Extensions

| Command | Effect |
| --- | --- |
| `ext:remote [--fresh]` | what the archive offers |
| `ext:search <query>` | search the archive |
| `ext:show <slug>` | description, versions, changelog, commands |
| `ext:outdated [--fresh]` | installed extensions with a newer release |
| `ext:install <slug> [--version=]` | installs from the archive, or from disk if the folder exists |
| `ext:update <slug>\|--all [--dry-run] [--force]` | updates one or all |

### Archive

| Command | Effect |
| --- | --- |
| `archive:ping` | reachability, channels, package count, signing |
| `version` | version, edited core files, extension counts, archive |
| `doctor` | environment checks, including zlib and legacy class aliases |

`--fresh` bypasses the on-disk cache. Browsing is cached so the admin panel
never waits on the network; downloads always re-resolve first, so an install
is never based on a stale index.

### Checking the machinery

```bash
php bin/selftest
```

Runs offline, needs no database, and writes only to a temp directory. It
checks the zip implementation both ways, traversal and tamper refusals,
manifest validation, version comparison, the tracked-path gate, the
gutting guard, backup round-trip, and that the hub's copy of the archive
code has not drifted from the bot's. Exits non-zero on failure, so it can
gate a deploy.
