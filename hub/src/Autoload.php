<?php

namespace Hub;

/**
 * The hub's autoloader.
 *
 * No composer on purpose. This folder is meant to be uploaded to a plain
 * host -- often one where you cannot run composer at all -- and it has no
 * third-party dependencies, so a twenty-line autoloader is the whole build
 * step.
 *
 * Two namespaces are mapped:
 *
 *   Hub\*            hub/src/
 *   Botex\Archive\*  hub/src/Archive/, falling back to the bot's src/Archive/
 *
 * The archive classes are shared with the bot deliberately: both ends have
 * to agree byte for byte about what a package is, and two copies of that
 * logic would eventually disagree. `php hub/bin/hub sync` copies them in,
 * and `verify` checks the copy still matches.
 */
final class Autoload
{
    public static function register(): void
    {
        $hub = dirname(__DIR__);

        spl_autoload_register(static function (string $class) use ($hub): void {
            foreach (self::candidates($class, $hub) as $file) {
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        });
    }

    /**
     * Files that could define a class, in priority order.
     *
     * @return array<string>
     */
    private static function candidates(string $class, string $hub): array
    {
        if (str_starts_with($class, 'Hub\\')) {
            return [
                $hub . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php',
            ];
        }

        if (str_starts_with($class, 'Botex\\Archive\\')) {
            $relative = str_replace('\\', '/', substr($class, strlen('Botex\\Archive\\'))) . '.php';

            return [
                // The bundled copy, which is what a deployed hub uses.
                $hub . '/src/Archive/' . $relative,
                // The bot's own copy, so a hub run from inside a checkout
                // works before `sync` has ever been run.
                dirname($hub) . '/src/Archive/' . $relative,
            ];
        }

        // Botex\Botex is referenced by Manifest for the package format
        // constant. Kept resolvable so the shared code needs no edits.
        if ($class === 'Botex\\Botex') {
            return [
                $hub . '/src/Archive/Botex.php',
                dirname($hub) . '/src/Botex.php',
            ];
        }

        return [];
    }
}
