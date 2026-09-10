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
        $problems = [];

        foreach ($manifest->requires as $slug => $constraint) {
            $required = $this->registry->find($slug);

            if ($required === null) {
                $problems[] = "{$manifest->slug} needs the {$slug} extension, which is not installed.";

                continue;
            }

            if (!$this->registry->isEnabled($slug)) {
                $problems[] = "{$manifest->slug} needs {$slug}, which is installed but disabled."
                    . " Enable it with: php bin/console ext:enable {$slug}";

                continue;
            }

            // An unparseable constraint is the manifest author's mistake,
            // not the operator's, so it is reported rather than silently
            // treated as satisfied.
            if ($constraint !== '*' && !Version::satisfies($required->version, $constraint)) {
                $problems[] = "{$manifest->slug} needs {$slug} {$constraint}, but {$required->version} is installed.";
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
