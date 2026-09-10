<?php

namespace Botex\Extension;

use Botex\Support\Log\Logger;

/**
 * Lifecycle operations. Kept separate from Registry so the read path
 * (the webhook) never carries code that can delete folders.
 */
class Manager
{
    public function __construct(
        private Registry $registry,
        private State $state,
        private SettingsFactory $settings,
        private \Botex\Bot\Action\ActionStore $actions,
        // The repository rather than JobService: removing an extension only
        // needs to delete rows, and the service would drag the Jobs
        // allowlist (and so the Registry) in behind it.
        private \Botex\Repository\JobRepository $jobs,
        private Dependencies $dependencies,
        private Logger $log
    ) {
    }

    /**
     * Runs install() on an extension already sitting in extensions/
     * and marks it enabled.
     */
    public function install(string $slug): string
    {
        $manifest = $this->manifest($slug);

        $this->requireDependencies($manifest);

        $this->registry->entryClass($manifest)::install();
        $this->state->enable($slug);

        return "Installed {$manifest->name} {$manifest->version}.";
    }

    public function enable(string $slug): string
    {
        $manifest = $this->manifest($slug);

        if ($this->state->isEnabled($slug)) {
            return "{$manifest->name} is already enabled.";
        }

        $this->requireDependencies($manifest);

        $this->state->enable($slug);

        return "Enabled {$manifest->name}.";
    }

    /**
     * @param bool $force switch it off even though something depends on it
     */
    public function disable(string $slug, bool $force = false): string
    {
        $manifest = $this->manifest($slug);

        if (!$this->state->isEnabled($slug)) {
            return "{$manifest->name} is already disabled.";
        }

        $this->guardDependents($slug, 'Disabling', $force);

        $this->state->disable($slug);

        return "Disabled {$manifest->name}.";
    }

    /**
     * Calls uninstall(), then deletes the folder.
     *
     * @param bool $keepFiles disable and clean up data, leave files on disk
     * @param bool $force     remove it even though something depends on it
     */
    public function remove(string $slug, bool $keepFiles = false, bool $force = false): string
    {
        $manifest = $this->manifest($slug);

        $this->guardDependents($slug, 'Removing', $force);

        try {
            $this->registry->entryClass($manifest)::uninstall();
        } catch (\Throwable $e) {
            // a broken extension must still be removable
            $this->log->exception($e, "uninstall() failed for {$slug}", context: [
                'extension' => $slug,
            ]);
        }

        $this->state->forget($slug);

        // Settings live outside the extension folder to survive updates,
        // so a real removal has to clear them explicitly.
        $this->settings->for($slug)->forget();

        // Buttons already sent stay on screen, but their bindings are
        // dead. Dropping them now means a press gets the stale notice
        // instead of sitting in the table until its ttl runs out.
        try {
            $this->actions->forgetExtension($slug);
        } catch (\Throwable $e) {
            // An unmigrated database must not block a removal.
            $this->log->exception($e, "Could not clear actions for {$slug}", context: [
                'extension' => $slug,
            ]);
        }

        // Scheduled work goes with the extension. Leaving the rows would
        // just have the worker pause them one by one as it found them.
        try {
            $this->jobs->forgetExtension($slug);
        } catch (\Throwable $e) {
            $this->log->exception($e, "Could not clear jobs for {$slug}", context: [
                'extension' => $slug,
            ]);
        }

        if ($keepFiles) {
            return "Removed {$manifest->name} (files kept).";
        }

        $this->deleteDirectory($manifest->path);

        // The folder is gone, but the cached scan still lists it. Anything
        // asking afterwards in this same process -- a panel rendering the
        // list, a reinstall -- would otherwise be told it is still there.
        $this->registry->refresh();

        return "Removed {$manifest->name} and deleted its files.";
    }

    /**
     * Refuses to switch on an extension whose foundations are not there.
     *
     * Checked at install and enable rather than at boot: a missing
     * dependency shows up at runtime as a class that will not autoload,
     * on whatever message happens to arrive first, and the operator has
     * no way to connect that to the extension they just turned on.
     */
    private function requireDependencies(Manifest $manifest): void
    {
        $problems = $this->dependencies->unmet($manifest);

        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems));
        }
    }

    /**
     * Refuses to pull the rug out from under another extension.
     *
     * Forcible, because an operator sorting out a broken pair has to be
     * able to switch either one off -- but not by accident.
     */
    private function guardDependents(string $slug, string $what, bool $force): void
    {
        $dependents = $this->dependencies->dependents($slug);

        if ($dependents === [] || $force) {
            return;
        }

        throw new \RuntimeException(
            "{$what} {$slug} would break " . implode(', ', $dependents)
            . ', which depends on it. Disable ' . (count($dependents) === 1 ? 'it' : 'them')
            . ' first, or repeat with --force.'
        );
    }

    private function manifest(string $slug): Manifest
    {
        $manifest = $this->registry->find($slug);

        if (!$manifest) {
            throw new \RuntimeException("Extension '{$slug}' was not found.");
        }

        return $manifest;
    }

    private function deleteDirectory(string $dir): void
    {
        $root = realpath($dir);
        $parent = realpath(dirname($dir));

        // never delete anything outside the extensions folder
        if ($root === false || $parent === false || dirname($root) !== $parent) {
            throw new \RuntimeException("Refusing to delete {$dir}.");
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }
}
