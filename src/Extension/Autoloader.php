<?php

namespace Botex\Extension;

/**
 * Maps Extensions\<Slug>\... to extensions/<Slug>/... at runtime.
 *
 * Registered instead of a composer PSR-4 entry so installing or
 * removing an extension never needs a dump-autoload.
 */
class Autoloader
{
    public const NAMESPACE = 'Extensions\\';

    private static bool $registered = false;

    public static function register(string $extensionsPath): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        $root = rtrim($extensionsPath, '/\\');

        spl_autoload_register(static function (string $class) use ($root): void {
            if (!str_starts_with($class, self::NAMESPACE)) {
                return;
            }

            $relative = substr($class, strlen(self::NAMESPACE));

            // reject traversal before it can reach the filesystem
            if (str_contains($relative, '..')) {
                return;
            }

            $file = $root . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}
