<?php

namespace Botex\Update;

use Botex\Archive\Hash;
use Botex\Archive\Package;
use Botex\Archive\Version;
use Botex\Extension\Dependencies;
use Botex\Extension\Manager;
use Botex\Extension\Registry;
use Botex\Extension\State;
use Botex\Remote\Cache;
use Botex\Remote\Downloader;
use Botex\Support\Config;
use Botex\Support\Log\Logger;

/**
 * Installs and updates one extension from the archive.
 *
 * The folder is replaced wholesale, which is safe precisely because the
 * things an admin configures do not live in it:
 *
 *   storage/extensions.json              the enabled flag
 *   storage/extension-settings/<Slug>.json  setting overrides
 *
 * Both are outside extensions/ and neither is touched here, so an update
 * that ships new defaults keeps every override the admin set. That was
 * already the design; this class is what finally depends on it.
 *
 * The swap is atomic in the sense that matters: the old folder is moved
 * aside, the new one moved in, and only then is the old one deleted. A
 * failure at any step puts the original back, so there is no window where
 * the extension is half-replaced and the Registry would load a mixture of
 * two versions.
 */
class ExtensionInstaller
{
    private string $extensionsPath;

    public function __construct(
        private Downloader $downloader,
        private Registry $registry,
        private State $state,
        private Manager $manager,
        private Dependencies $dependencies,
        private Inventory $inventory,
        private Backup $backup,
        private Cache $cache,
        private Logger $log,
        Config $config
    ) {
        $this->extensionsPath = rtrim(
            (string) $config->get('paths.extensions', $this->inventory->root() . '/extensions'),
            '/\\'
        );
    }

    /**
     * Works out what installing this package would do.
     *
     * @throws UpdateException
     */
    public function plan(Package $package): Plan
    {
        $slug = $package->slug();
        $installed = $this->registry->find($slug);

        $plan = new Plan(
            slug: $slug,
            from: $installed?->version ?? '',
            to: $package->version()
        );

        if (!$package->manifest->isExtension()) {
            $plan->problem("'{$slug}' is a " . $package->manifest->type . ' package, not an extension.');

            return $plan;
        }

        foreach ($package->manifest->unmet() as $problem) {
            $plan->problem($problem);
        }

        // What the package needs from this bot rather than from this core:
        // the archive cannot answer it, because only the install being
        // written to knows which extensions are there and switched on.
        foreach ($this->dependencies->check($slug, $package->manifest->requiredExtensions()) as $problem) {
            $plan->problem($problem);
        }

        $target = $this->extensionsPath . '/' . $slug;

        // A downgrade or reinstall is allowed, but it is worth naming: the
        // operator asked for a specific version and should see that it is
        // not moving forward.
        if ($installed !== null && Version::isValid($installed->version)) {
            $direction = Version::compare($package->version(), $installed->version);

            if ($direction < 0) {
                $plan->problem(
                    "this would downgrade {$slug} from {$installed->version} to {$package->version()}"
                        . ' (pass --force to allow it)'
                );
            }
        }

        foreach ($package->files() as $path) {
            $absolute = $target . '/' . $path;

            if (!is_file($absolute)) {
                $plan->add(Plan::ADD, 'extensions/' . $slug . '/' . $path);
                continue;
            }

            $current = Hash::fileOrNull($absolute);
            $shipped = $package->manifest->files[$path] ?? '';

            $plan->add(
                $current !== null && Hash::matches($shipped, $current)
                    ? Plan::IDENTICAL
                    : Plan::REPLACE,
                'extensions/' . $slug . '/' . $path
            );
        }

        // Files the old version had and the new one does not. Reported as
        // deletions because the folder is replaced, not merged -- leaving a
        // stale class behind is how an old handler keeps being routed to.
        foreach ($this->installedFiles($slug) as $path) {
            if (!isset($package->manifest->files[$path])) {
                $plan->add(Plan::DELETE, 'extensions/' . $slug . '/' . $path);
            }
        }

        return $plan;
    }

    /**
     * Downloads, verifies and installs an extension.
     *
     * @param  string      $slug    extension to install
     * @param  string|null $version exact version, or the newest
     * @param  bool        $force   allow a downgrade
     *
     * @throws UpdateException
     */
    public function install(string $slug, ?string $version = null, bool $force = false): Result
    {
        $package = $this->downloader->fetch($slug, $version);
        $plan = $this->plan($package);

        if (!$force && !$plan->isSafe()) {
            throw new UpdateException(
                "Refusing to install {$slug}: " . implode('; ', $plan->problems())
            );
        }

        return $this->apply($package, $plan);
    }

    /**
     * Installs an already-verified package.
     *
     * Separate from install() so an offline install from a local .botex file
     * shares exactly this code path.
     *
     * @throws UpdateException
     */
    public function apply(Package $package, ?Plan $plan = null): Result
    {
        $slug = $package->slug();
        $plan ??= $this->plan($package);
        $target = $this->extensionsPath . '/' . $slug;
        $existed = is_dir($target);

        $wasEnabled = $existed ? $this->state->isEnabled($slug) : null;

        // Only when there is something to lose. A first install overwrites
        // nothing, so a backup of it would be an empty directory and a line
        // telling the operator their previous version is safe -- when there
        // was no previous version.
        $label = '';

        if ($existed) {
            $label = $this->backup->begin("install {$slug} {$package->version()}");
            $this->backup->keepDirectory($label, 'extensions/' . $slug);
        }

        // Unpacked next to the target, on the same filesystem, so the move
        // into place is a rename rather than a copy.
        $staging = $this->extensionsPath . '/.botex-staging-' . bin2hex(random_bytes(4));
        $retired = $this->extensionsPath . '/.botex-old-' . bin2hex(random_bytes(4));

        try {
            $package->extractTo($staging);

            // The staged folder must hold what the manifest promised before
            // anything is swapped, since after the swap the old version is
            // already gone.
            $this->verifyStaging($staging, $package);

            if ($existed && !@rename($target, $retired)) {
                throw new UpdateException(
                    "Could not move the existing {$slug} aside. Check permissions on {$target}."
                );
            }

            if (!@rename($staging, $target)) {
                // Put the original back before giving up.
                if ($existed) {
                    @rename($retired, $target);
                }

                throw new UpdateException("Could not move the new {$slug} into place.");
            }

            $this->deleteDirectory($retired);
        } catch (\Throwable $e) {
            $this->deleteDirectory($staging);

            // If the retired folder is still there, the swap did not
            // complete, so the original goes back.
            if (is_dir($retired) && !is_dir($target)) {
                @rename($retired, $target);
            }

            $this->deleteDirectory($retired);

            throw $e instanceof UpdateException
                ? $e
                : new UpdateException("Installing {$slug} failed: " . $e->getMessage(), 0, $e);
        }

        // The Registry cached its manifests before the swap, so anything
        // asking about this extension now would see the old version -- or,
        // on a first install, not see it at all and skip its install().
        $this->registry->refresh();

        // Separately, the archive's cached index still lists the version that
        // was installed a moment ago, which would show as an update pending.
        $this->cache->flush();

        $migrated = $this->migrate($slug);

        // A new install is enabled; an update keeps whatever it was, so
        // updating a deliberately disabled extension does not switch it on.
        if ($wasEnabled === null) {
            $this->state->enable($slug);
        }

        $this->log->info('Extension installed from the archive', [
            'extension' => $slug,
            'version' => $package->version(),
            'backup' => $label ?: null,
        ]);

        return new Result(
            slug: $slug,
            from: $plan->from,
            to: $package->version(),
            plan: $plan,
            backup: $label,
            migrated: $migrated,
            enabled: $wasEnabled ?? true
        );
    }

    /**
     * Runs the extension's own install() so a new version can add tables.
     *
     * A throw is recorded and reported rather than fatal: the files are
     * already in place, and an install() that fails leaves a working
     * extension whose migration needs attention -- not a half-installed
     * folder to unwind.
     */
    private function migrate(string $slug): ?string
    {
        try {
            $manifest = $this->registry->find($slug);

            if ($manifest === null) {
                // Report why, rather than guessing at extension.json. The
                // Registry already recorded the reason it skipped the folder,
                // and a parse error names the actual problem; only when there
                // is no recorded error is the folder genuinely absent.
                $reason = $this->registry->errors()[$slug] ?? null;

                return $reason !== null
                    ? "{$slug} could not be read after installing: {$reason}"
                    : "{$slug} is not in " . basename($this->extensionsPath)
                        . ' after installing, so its install() did not run.';
            }

            $this->registry->entryClass($manifest)::install();

            return null;
        } catch (\Throwable $e) {
            $this->log->exception($e, "install() failed for {$slug} after updating", context: [
                'extension' => $slug,
            ]);

            return $e->getMessage();
        }
    }

    /** @throws UpdateException */
    private function verifyStaging(string $staging, Package $package): void
    {
        foreach ($package->files() as $path) {
            $file = $staging . '/' . $path;

            if (!is_file($file)) {
                throw new UpdateException("The package did not unpack '{$path}'.");
            }

            $hash = Hash::fileOrNull($file);
            $expected = $package->manifest->files[$path] ?? '';

            if ($hash === null || !Hash::matches($expected, $hash)) {
                throw new UpdateException("'{$path}' does not match the package after unpacking.");
            }
        }

        // Belt and braces: the Registry looks for these two by name, so an
        // extension missing either would install into a folder the Registry
        // then skips. Manifest validation already rejects that, which makes
        // this a guard against a future change loosening it.
        foreach (['extension.json', 'Extension.php'] as $required) {
            if (!is_file($staging . '/' . $required)) {
                throw new UpdateException("The package is missing {$required}.");
            }
        }
    }

    /**
     * Removes an extension, reusing the existing lifecycle so data, jobs,
     * actions and settings are cleaned up exactly as `ext:remove` does.
     */
    public function remove(string $slug, bool $keepFiles = false): string
    {
        $message = $this->manager->remove($slug, $keepFiles);

        $this->cache->flush();

        return $message;
    }

    /**
     * Paths currently installed for an extension, relative to its folder.
     *
     * @return array<string>
     */
    private function installedFiles(string $slug): array
    {
        $target = $this->extensionsPath . '/' . $slug;

        if (!is_dir($target)) {
            return [];
        }

        return array_keys(Hash::directory($target));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $root = realpath($directory);
        $parent = realpath($this->extensionsPath);

        // Only ever inside the extensions folder, whatever was passed.
        if ($root === false || $parent === false) {
            return;
        }

        if (dirname(str_replace('\\', '/', $root)) !== str_replace('\\', '/', $parent)) {
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
