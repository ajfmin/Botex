<?php

namespace Botex;

use Botex\Support\Log\Log;

/**
 * Keeps extensions written against the old `App\` namespace loading.
 *
 * Botex used to ship its core as `App\`. Renaming it would otherwise break
 * every extension already published, since an extension imports core
 * classes by name (`use App\Extension\AbstractExtension;`) and the runtime
 * autoloader has no idea those imports moved.
 *
 * Rather than alias ~130 classes eagerly, this registers an autoloader:
 * when something asks for a class under `App\`, the matching `Botex\`
 * class is loaded and aliased to the old name on the spot. An install with
 * no legacy extensions never aliases anything and pays only for one
 * closure on the autoload stack.
 *
 * This is a migration aid, not a supported surface. Aliases are recorded
 * and surfaced by `php bin/console doctor` so an operator can see which
 * extensions still need updating, and it is fair to delete this class once
 * nothing reports.
 */
final class Compat
{
    /** The namespace extensions were written against before the rename. */
    public const LEGACY = 'App\\';

    public const CURRENT = 'Botex\\';

    private static bool $registered = false;

    /** @var array<string, string> legacy name => current name */
    private static array $aliased = [];

    /** Called once by the bootstrap, before extensions can load. */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        // Appended rather than prepended: composer's autoloader gets first
        // refusal, so a real `App\` class (someone's own code, say) still
        // wins over an alias invented here.
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, self::LEGACY)) {
                return;
            }

            $current = self::CURRENT . substr($class, strlen(self::LEGACY));

            // No class_exists() recursion risk: $current is under Botex\,
            // which this autoloader ignores.
            if (!class_exists($current) && !interface_exists($current) && !trait_exists($current)) {
                return;
            }

            class_alias($current, $class);

            self::$aliased[$class] = $current;

            Log::notice('Legacy App\\ class aliased; the extension using it should be updated.', [
                'legacy' => $class,
                'current' => $current,
            ]);
        });
    }

    /**
     * Legacy names that were actually resolved this process.
     *
     * Empty is the healthy answer, and means this shim can be removed.
     *
     * @return array<string, string>
     */
    public static function aliased(): array
    {
        return self::$aliased;
    }
}
