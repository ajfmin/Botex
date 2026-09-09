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

        $this->state->enable($slug);

        return "Enabled {$manifest->name}.";
    }

    public function disable(string $slug): string
    {
        $manifest = $this->manifest($slug);

        if (!$this->state->isEnabled($slug)) {
            return "{$manifest->name} is already disabled.";
        }

        $this->state->disable($slug);

        return "Disabled {$manifest->name}.";
    }

    /**
     * Calls uninstall(), then deletes the folder.
     *
     * @param bool $keepFiles disable and clean up data, leave files on disk
     */
    public function remove(string $slug, bool $keepFiles = false): string
    {
        $manifest = $this->manifest($slug);

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
