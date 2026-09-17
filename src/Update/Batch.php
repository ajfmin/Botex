<?php

namespace Botex\Update;

use Botex\Archive\Package;
use Botex\Archive\Version;
use Botex\Botex;
use Botex\Extension\Registry;
use Botex\Remote\Catalog;
use Botex\Remote\Downloader;
use Botex\Remote\Listing;
use Botex\Remote\RemoteException;
use Botex\Support\Log\Logger;

/**
 * Everything this install is behind on, applied in one run.
 *
 * `ext:update --all` already updates every extension, and `core:update`
 * updates the core; what was missing was the thing an operator actually
 * wants to type after being away for a month -- one command that looks
 * at the hub and brings the whole install forward.
 *
 * Two rules decide how it behaves, and both are about not leaving a
 * half-updated install behind.
 *
 * **It always looks.** The cache exists so the admin panel never waits
 * on the network; a command whose entire purpose is "check, then apply"
 * must not answer from it. Every lookup here is fresh.
 *
 * **Extensions first, the core last.** A core update rewrites the very
 * classes this process is running, so whatever happens after it runs on
 * a mixture of the code that booted and the code now on disk. Putting it
 * last means nothing runs in that state: the command ends, and the
 * operator restarts the worker as they would after any core update.
 *
 * The cost of that order is one real case -- an extension release that
 * needs the core release in the same run. It is not left to fail with a
 * requirement error nobody can act on: the package says which core it
 * wants, so when the pending core would satisfy it and the running one
 * would not, the extension is reported as deferred and the summary says
 * to run the command again. See [[Step]].
 *
 * Nothing here throws. One target failing is exactly the case this class
 * exists to survive, so every outcome is a Step and the caller decides
 * how loud to be about it.
 */
class Batch
{
    public function __construct(
        private Catalog $catalog,
        private Registry $registry,
        private Downloader $downloader,
        private ExtensionInstaller $installer,
        private CoreUpdater $core,
        private Logger $log
    ) {
    }

    /**
     * Extension slugs with a newer release, from a fresh look at the hub.
     *
     * @return array<string>
     */
    public function outdatedExtensions(): array
    {
        $available = $this->catalog->extensions(fresh: true);
        $slugs = [];

        foreach ($this->registry->all() as $manifest) {
            $listing = $available[$manifest->slug] ?? null;

            if ($listing !== null && $listing->isNewerThan($manifest->version)) {
                $slugs[] = $manifest->slug;
            }
        }

        return $slugs;
    }

    /** The core release on offer, when it is newer than what is running. */
    public function newerCore(): ?Listing
    {
        $listing = $this->catalog->core(fresh: true);

        return $listing !== null && $listing->isNewerThan(Botex::VERSION) ? $listing : null;
    }

    /**
     * Applies everything outstanding.
     *
     * @param  bool $dryRun plan every target and write nothing
     * @param  bool $force  apply despite conflicts, as the single-target
     *                      commands mean it
     * @return array<Step> one per target, in the order they were tried
     */
    public function run(bool $dryRun = false, bool $force = false): array
    {
        $core = $this->newerCore();
        $steps = [];

        foreach ($this->outdatedExtensions() as $slug) {
            $steps[] = $this->extension($slug, $core, $dryRun, $force);
        }

        if ($core !== null) {
            $steps[] = $this->core($core, $dryRun, $force);
        }

        return $steps;
    }

    /** One extension, refusing rather than throwing. */
    private function extension(string $slug, ?Listing $core, bool $dryRun, bool $force): Step
    {
        try {
            $package = $this->downloader->fetch($slug);
            $plan = $this->installer->plan($package);

            if ($dryRun) {
                return Step::planned($slug, $plan);
            }

            if (!$plan->isSafe() && !$force) {
                $waiting = $this->waitingOnCore($package, $core);

                return $waiting === null
                    ? Step::failed($slug, implode('; ', $plan->problems()), $plan)
                    : Step::deferred($slug, $waiting, $plan);
            }

            return Step::updated($slug, $this->installer->apply($package, $plan));
        } catch (RemoteException | UpdateException $e) {
            return Step::failed($slug, $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->exception($e, 'Batch update failed for an extension', context: [
                'extension' => $slug,
            ]);

            return Step::failed($slug, $e->getMessage());
        }
    }

    /** The core, last, and only when something newer is published. */
    private function core(Listing $listing, bool $dryRun, bool $force): Step
    {
        try {
            if ($dryRun) {
                return Step::planned(
                    'core',
                    $this->core->plan($this->downloader->fetch('core', $listing->version))
                );
            }

            return Step::updated('core', $this->core->update($listing->version, $force));
        } catch (RemoteException | UpdateException $e) {
            return Step::failed('core', $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->exception($e, 'Batch update failed for the core');

            return Step::failed('core', $e->getMessage());
        }
    }

    /**
     * Why an extension is stuck, when the reason is a core update that is
     * part of this run.
     *
     * Read off the package rather than matched out of the refusal text:
     * the constraint is right there in the manifest, and a message is a
     * message. Returns null when the pending core would not help, which
     * keeps a genuinely unsatisfiable requirement reported as a failure.
     */
    private function waitingOnCore(Package $package, ?Listing $core): ?string
    {
        if ($core === null) {
            return null;
        }

        $constraint = $package->manifest->requires['botex'] ?? null;

        if (!is_string($constraint) || $constraint === '') {
            return null;
        }

        if (Version::satisfies(Botex::VERSION, $constraint)) {
            return null;
        }

        return !Version::satisfies($core->version, $constraint)
            ? null
            : "needs Botex {$constraint}; the core update in this run provides it. "
                . 'Run update:all again once it has been applied';
    }
}
