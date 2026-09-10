<?php

namespace Botex\Extension;

use Botex\Archive\Version;

/**
 * Which extensions need which other extensions.
 *
 * An extension that builds on another one -- adding screens to it, calling
 * its services -- is broken in a way nobody can read when the one below it
 * is missing or switched off: a class that will not autoload, on whatever
 * message happens to arrive first. Declaring the need in extension.json
 * turns that into a sentence at install time.
 *
 * Read-only. Manager is what refuses an operation; this only answers what
 * is missing and who would be left dangling.
 */
class Dependencies
{
    public function __construct(
        private Registry $registry
    ) {
    }

    /**
     * Why this extension cannot run yet, one sentence per problem.
     *
     * Empty means every requirement is on disk, enabled, and new enough.
     *
     * @return array<string>
     */
    public function unmet(Manifest $manifest): array
    {
        return $this->check($manifest->slug, $manifest->requires);
    }

    /**
     * The same question for an extension that is not installed yet.
     *
     * Takes the slug and the requirements on their own, because at install
     * time the only manifest that exists is the package's -- the archive's
     * own, which is a different class and describes a folder that is not on
     * disk.
     *
     * @param  array<string, string> $requires slug => constraint
     * @return array<string>
     */
    public function check(string $slug, array $requires): array
    {
        $problems = [];

        foreach ($requires as $needs => $constraint) {
            $required = $this->registry->find($needs);

            if ($required === null) {
                $problems[] = "{$slug} needs the {$needs} extension, which is not installed."
                    . " Install it with: php bin/console ext:install {$needs}";

                continue;
            }

            if (!$this->registry->isEnabled($needs)) {
                $problems[] = "{$slug} needs {$needs}, which is installed but disabled."
                    . " Enable it with: php bin/console ext:enable {$needs}";

                continue;
            }

            // An unparseable constraint is the manifest author's mistake,
            // not the operator's, so it is reported rather than silently
            // treated as satisfied.
            if ($constraint !== '*' && !Version::satisfies($required->version, $constraint)) {
                $problems[] = "{$slug} needs {$needs} {$constraint}, but {$required->version} is installed.";
            }
        }

        return $problems;
    }

    /**
     * Enabled extensions that would break if this one went away.
     *
     * @return array<string> slugs, in scan order
     */
    public function dependents(string $slug): array
    {
        $dependents = [];

        foreach ($this->registry->enabled() as $manifest) {
            if ($manifest->slug !== $slug && $manifest->requiresExtension($slug)) {
                $dependents[] = $manifest->slug;
            }
        }

        return $dependents;
    }
}
