<?php

namespace Botex\Extension;

/**
 * Default implementation of every hook, so an extension only declares
 * the parts it actually uses and new hooks can be added to the
 * interface without breaking anything already installed.
 */
abstract class AbstractExtension implements ExtensionInterface
{
    public static function commands(): array
    {
        return [];
    }

    public static function callbacks(): array
    {
        return [];
    }

    public static function flows(): array
    {
        return [];
    }

    public static function adminSections(): array
    {
        return [];
    }

    public static function runnables(): array
    {
        return [];
    }

    public static function jobs(): array
    {
        return [];
    }

    public static function install(): void
    {
        //
    }

    public static function uninstall(): void
    {
        //
    }
}
