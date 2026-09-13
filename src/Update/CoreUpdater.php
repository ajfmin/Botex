<?php

namespace Botex\Update;

use Botex\Archive\Hash;
use Botex\Archive\Package;
use Botex\Archive\Version;
use Botex\Botex;
use Botex\Remote\Cache;
use Botex\Remote\Downloader;
use Botex\Support\Log\Logger;

/**
 * Replaces the core in place, and refuses to do it carelessly.
 *
 * The rule this class exists to enforce: if a tracked core file differs
 * from what was installed, the operator edited it, and an update would
 * throw that work away. So the whole update is refused, the files are
 * named, and nothing is written. `--force` overrides it and takes a backup
 * first, which is the honest version of "I know, do it anyway".
 *
 * Refusing wholesale rather than per file is deliberate: a core is a
 * coherent set of classes, and applying half of one -- the untouched files
 * new, the edited ones old -- produces a combination that was never tested
 * and can fail in ways neither version does.
 *
 * What is never touched, whatever a package lists:
 *
 *   config/    .env    storage/    vendor/    extensions/
 *
 * Inventory::isTracked() is the gate, applied to every path before it is
 * written, so a malicious package cannot reach configuration by listing it.
 */
class CoreUpdater
{
    public function __construct(
        private Downloader $downloader,
        private Inventory $inventory,
        private CoreManifest $manifest,
        private Backup $backup,
        private Cache $cache,
        private Logger $log
    ) {
    }

    /** The version running right now. */
    public function installed(): string
    {
        return Botex::VERSION;
    }

    /**
     * Works out what a core update would do.
     *
     * @throws UpdateException
     */
    public function plan(Package $package): Plan
    {
        $plan = new Plan(
            slug: 'core',
            from: $this->installed(),
            to: $package->version()
        );

        if (!$package->manifest->isCore()) {
            $plan->problem('that package is not a core release.');

            return $plan;
        }

        foreach ($package->manifest->unmet() as $problem) {
            $plan->problem($problem);
        }

        if (Version::compare($package->version(), $this->installed()) < 0) {
            $plan->problem(
                "this would downgrade the core from {$this->installed()} to {$package->version()}"
            );
        }

        // Without a baseline there is no way to tell an edit from an
        // upstream change, and guessing wrong loses the operator's work.
        if (!$this->inventory->isUsable()) {
            $plan->problem(
                'there is no record of what was installed, so local changes cannot be detected. '
                    . 'Run "core:adopt" to record the current files as your baseline first'
            );
        }

        $dirty = $this->inventory->isUsable() ? $this->inventory->dirty() : [];
        $recorded = $this->inventory->hashes();

        foreach ($package->files() as $path) {
            // The gate: a package may only write inside the core surface.
            if (!Inventory::isTracked($path)) {
                $plan->add(Plan::BLOCKED, $path, 'outside the core surface');
                continue;
            }

            $absolute = $this->inventory->absolute($path);
            $shipped = $package->manifest->files[$path] ?? '';
            $current = Hash::fileOrNull($absolute);

            if ($current === null) {
                $plan->add(Plan::ADD, $path);
                continue;
            }

            // On disk, but the core never shipped it: the operator put it
            // there, and this release happens to want the same path. There
            // is no core version of it to fall back to, so the updater
            // stops rather than choosing which of the two survives.
            if (!$this->inventory->owns($path)) {
                $plan->add(Plan::COLLISION, $path, 'yours, and new in this release');
                continue;
            }

            if (Hash::matches($shipped, $current)) {
                $plan->add(Plan::IDENTICAL, $path);
                continue;
            }

            // Edited locally *and* changed upstream: replacing it discards
            // the edit, which is the case that stops the update.
            if (in_array($path, $dirty, true)) {
                $plan->add(Plan::CONFLICT, $path, 'you edited this file');
                continue;
            }

            // Present, differing from the package, and matching what was
            // installed -- an ordinary upstream change.
            $plan->add(Plan::REPLACE, $path);
        }

        // An inventory written before ownership was a concept lists every
        // file that was on disk, the operator's included. It is still a
        // fine record of edits, but it cannot authorise a deletion on its
        // own -- so the installed release's manifest is asked as well, and
        // only files it claims are considered the core's.
        $unscoped = !$this->inventory->isScoped() && $this->manifest->exists();

        // A file this release drops. Only ever one that was shipped before
        // and is unmodified: anything else is the operator's and stays.
        foreach (array_keys($recorded) as $path) {
            if (isset($package->manifest->files[$path])) {
                continue;
            }

            if (!Inventory::isTracked($path)) {
                continue;
            }

            if ($unscoped && !$this->manifest->ships($path)) {
                continue;
            }

            if (in_array($path, $dirty, true)) {
                $plan->add(Plan::CONFLICT, $path, 'removed upstream, but you edited it');
                continue;
            }

            if (is_file($this->inventory->absolute($path))) {
                $plan->add(Plan::DELETE, $path);
            }
        }

        $this->guardAgainstGutting($plan, $recorded);

        return $plan;
    }

    /**
     * Refuses a "core" package that would delete most of the core.
     *
     * A real release replaces files and drops a few; it never removes the
     * bulk of the tree. A package that would is either malformed -- someone
     * published a fragment as a full core -- or hostile, and either way
     * applying it leaves an install that cannot boot. Since a core update
     * rewrites the very code performing the update, there is no second
     * chance to notice afterwards.
     *
     * Deliberately not forceable: --force exists to say "discard my edits",
     * not "delete my installation". Publishing a complete package is the
     * fix.
     *
     * @param array<string,string> $recorded
     */
    private function guardAgainstGutting(Plan $plan, array $recorded): void
    {
        $tracked = count(array_filter(array_keys($recorded), [Inventory::class, 'isTracked']));

        if ($tracked === 0) {
            return;
        }

        $deletions = $plan->count(Plan::DELETE);

        // A tenth of the tree is already a lot for one release; ten times
        // that would have to be a mistake.
        if ($deletions <= max(5, (int) ($tracked * 0.1))) {
            return;
        }

        $plan->problem(sprintf(
            'this package would delete %d of %d core files, which no real release does. '
                . 'It looks incomplete rather than new, so it is refused',
            $deletions,
            $tracked
        ));
    }

    /**
     * Downloads and applies a core release.
     *
     * @param  string|null $version exact version, or the newest
     * @param  bool        $force   apply despite conflicts, after backing up
     *
     * @throws UpdateException
     */
    public function update(?string $version = null, bool $force = false): Result
    {
        $package = $this->downloader->fetch('core', $version);
        $plan = $this->plan($package);

        // Blocked paths and unmet requirements are never forceable: one is a
        // package trying to write outside its remit, the other is a release
        // that cannot run here. --force is only ever about conflicts.
        if ($plan->blocked() !== []) {
            throw new UpdateException(
                'Refusing this core package: it tries to write outside the core surface ('
                    . implode(', ', array_slice($plan->blocked(), 0, 5)) . ').'
            );
        }

        if ($plan->problems() !== []) {
            throw new UpdateException(
                "Refusing to update the core: " . implode('; ', $plan->problems()) . '.'
            );
        }

        // Before the --force check, deliberately: forcing means "discard my
        // edit to a core file", and there is no edit here to discard -- the
        // file is the operator's outright.
        if ($plan->collisions() !== []) {
            throw new UpdateException($this->explainCollisions($plan));
        }

        if ($plan->conflicts() !== [] && !$force) {
            throw new UpdateException($this->explainConflicts($plan));
        }

        if ($plan->isEmpty()) {
            return new Result(
                slug: 'core',
                from: $plan->from,
                to: $plan->to,
                plan: $plan,
                backup: ''
            );
        }

        return $this->apply($package, $plan, $force);
    }

    /**
     * Writes a planned core update.
     *
     * Ordering matters: every file is staged and verified in a temp
     * directory first, so a package that fails to unpack cannot leave the
     * core half-written. Only once all of it is on disk and verified does
     * anything get moved into place.
     *
     * @throws UpdateException
     */
    public function apply(Package $package, Plan $plan, bool $force = false): Result
    {
        $staging = $this->inventory->root() . '/storage/.botex-core-' . bin2hex(random_bytes(4));
        $label = $this->backup->begin("core {$plan->from} -> {$plan->to}");

        $written = [];
        $dependencies = false;

        try {
            $package->extractTo($staging);

            $replace = array_merge(
                $plan->paths(Plan::ADD),
                $plan->paths(Plan::REPLACE),
                $force ? $plan->conflicts() : []
            );

            // Verify the whole staged set before touching the live tree.
            foreach ($replace as $path) {
                $staged = $staging . '/' . $path;

                if (!is_file($staged)) {
                    throw new UpdateException("The package did not unpack '{$path}'.");
                }

                $hash = Hash::fileOrNull($staged);
                $expected = $package->manifest->files[$path] ?? '';

                if ($hash === null || !Hash::matches($expected, $hash)) {
                    throw new UpdateException("'{$path}' does not match the package after unpacking.");
                }
            }

            // Back up everything about to change, including deletions, so a
            // rollback can put all of it back.
            foreach (array_merge($replace, $plan->paths(Plan::DELETE)) as $path) {
                $this->backup->keep($label, $path);
            }

            foreach ($replace as $path) {
                // Re-checked at the point of writing, not just at planning
                // time, so nothing can slip between the two.
                if (!Inventory::isTracked($path)) {
                    throw new UpdateException("Refusing to write '{$path}'.");
                }

                $target = $this->inventory->absolute($path);
                $directory = dirname($target);

                if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new UpdateException("Could not create {$directory}");
                }

                if (!@copy($staging . '/' . $path, $target)) {
                    throw new UpdateException("Could not write {$path}. Check file permissions.");
                }

                $written[$path] = $package->manifest->files[$path] ?? Hash::file($target);

                if ($path === 'composer.json') {
                    $dependencies = true;
                }
            }

            $removed = [];

            foreach ($plan->paths(Plan::DELETE) as $path) {
                if (!Inventory::isTracked($path)) {
                    continue;
                }

                if (@unlink($this->inventory->absolute($path))) {
                    $removed[] = $path;
                }
            }

            // Recorded after the writes, so the inventory describes what is
            // actually on disk. A crash before this leaves a stale record,
            // which reports files as dirty -- noisy, but never a silent
            // overwrite, which is the right way round to fail.
            $this->inventory->merge(
                $written + $this->baselineForIdentical($package, $plan, $written),
                $package->version()
            );

            if ($removed !== []) {
                $this->inventory->forget($removed, $package->version());
            }

            // The release's own list of what it ships, so a later
            // `core:adopt` on this install knows which files are ours
            // without having to ask the archive.
            $this->manifest->write(array_keys($package->manifest->files), $package->version());
        } catch (\Throwable $e) {
            $this->deleteDirectory($staging);

            // Files already copied are put back from the backup, so a
            // failure partway through does not leave a mixed core.
            $restored = 0;

            if ($written !== []) {
                try {
                    $restored = count($this->backup->restore($label));
                } catch (\Throwable $restoreFailure) {
                    $this->log->exception($restoreFailure, 'Could not restore after a failed core update');

                    throw new UpdateException(
                        'The core update failed and the automatic restore also failed. '
                            . "Restore storage/backups/{$label} by hand. Original error: "
                            . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $this->log->exception($e, 'Core update failed and was rolled back', context: [
                'backup' => $label,
                'restored' => $restored,
            ]);

            throw $e instanceof UpdateException
                ? $e
                : new UpdateException('The core update failed: ' . $e->getMessage(), 0, $e);
        }

        $this->deleteDirectory($staging);
        $this->cache->flush();

        $this->log->info('Core updated from the archive', [
            'from' => $plan->from,
            'to' => $plan->to,
            'backup' => $label,
        ]);

        return new Result(
            slug: 'core',
            from: $plan->from,
            to: $plan->to,
            plan: $plan,
            backup: $label,
            dependenciesChanged: $dependencies
        );
    }

    /**
     * Baselines the files the package shipped unchanged.
     *
     * Without this, only written files get a fresh hash, so a file whose
     * local edit happens to match what upstream now ships stays recorded
     * against the *old* release forever. It reads as permanently edited, and
     * at the next release that touches it the plan calls it a conflict --
     * "you edited this file" when the running bytes are upstream's own.
     * That either blocks a legitimate update or teaches the operator to
     * reach for --force, which is the habit this whole class exists to avoid.
     *
     * Not an adoption of unknown content: each file is re-hashed here and
     * recorded only when it still equals the manifest. Anything that moved
     * since planning is left alone and keeps reporting as dirty.
     *
     * @param  array<string,string> $written already recorded by the caller
     * @return array<string,string>
     */
    private function baselineForIdentical(Package $package, Plan $plan, array $written): array
    {
        $baseline = [];

        foreach ($plan->paths(Plan::IDENTICAL) as $path) {
            if (isset($written[$path]) || !Inventory::isTracked($path)) {
                continue;
            }

            $shipped = $package->manifest->files[$path] ?? '';
            $current = Hash::fileOrNull($this->inventory->absolute($path));

            if ($shipped === '' || $current === null || !Hash::matches($shipped, $current)) {
                continue;
            }

            $baseline[$path] = $shipped;
        }

        return $baseline;
    }

    /**
     * Restores a backup.
     *
     * @return array<string> paths restored
     *
     * @throws UpdateException
     */
    public function rollback(?string $label = null): array
    {
        $resolved = $this->backup->label($label);
        $restored = $this->backup->restore($resolved);

        // The tree changed underneath the record, so it is rebuilt from what
        // is now on disk. The version is whatever the restored Botex.php
        // says, which is why it is read back rather than assumed -- and the
        // restored release's own file list scopes it, so files the operator
        // added are not adopted as core on the way back.
        $this->inventory->record(
            $this->versionOnDisk(),
            $this->manifest->exists() ? $this->manifest->paths() : null
        );
        $this->cache->flush();

        $this->log->warning('Rolled back to a backup', [
            'backup' => $resolved,
            'files' => count($restored),
        ]);

        return $restored;
    }

    /**
     * The version in src/Botex.php on disk, which after a rollback is not
     * the one this process booted with.
     */
    private function versionOnDisk(): string
    {
        $file = $this->inventory->absolute('src/Botex.php');

        if (!is_file($file)) {
            return Botex::VERSION;
        }

        $contents = (string) @file_get_contents($file);

        return preg_match("/VERSION\s*=\s*'([^']+)'/", $contents, $matches) === 1
            ? $matches[1]
            : Botex::VERSION;
    }

    /** The message a refused update prints. */
    private function explainConflicts(Plan $plan): string
    {
        $conflicts = $plan->conflicts();
        $shown = array_slice($conflicts, 0, 10);

        $lines = [
            'Refusing to update the core: you have edited '
                . count($conflicts) . ' file' . (count($conflicts) === 1 ? '' : 's')
                . ' this release also changes.',
            '',
        ];

        foreach ($shown as $path) {
            $lines[] = '  ! ' . $path;
        }

        if (count($conflicts) > count($shown)) {
            $lines[] = '  ... and ' . (count($conflicts) - count($shown)) . ' more';
        }

        return implode(PHP_EOL, [
            ...$lines,
            '',
            'Your options:',
            '  core:diff                    see exactly what you changed',
            '  revert those files, then core:update',
            '  core:update --force          apply anyway; the originals go to storage/backups/',
            '',
            'A file you added yourself is never in this list: the core only owns what it shipped.',
        ]);
    }

    /**
     * The message for a release landing on a file the operator owns.
     *
     * Says which file and what to do, and offers no flag: renaming is the
     * only resolution that keeps both, and picking a winner on the
     * operator's behalf is exactly what this whole class exists to avoid.
     */
    private function explainCollisions(Plan $plan): string
    {
        $collisions = $plan->collisions();

        $lines = [
            'Core update conflict:',
            '',
        ];

        foreach ($collisions as $path) {
            $lines[] = '  ' . $path;
        }

        return implode(PHP_EOL, [
            ...$lines,
            '',
            count($collisions) === 1
                ? 'A user-owned file already exists at a path introduced by the new Botex version.'
                : 'User-owned files already exist at paths introduced by the new Botex version.',
            '',
            'Rename or move the custom file before updating.',
            'Nothing has been written, and --force does not apply: the core has no version of',
            'this file to restore, so forcing could only mean deleting yours.',
        ]);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $root = realpath($directory);

        // Only ever a staging folder this class made, under storage/.
        if ($root === false || !str_contains(str_replace('\\', '/', $root), '/storage/.botex-core-')) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    }
}
