<?php

namespace Botex;

/**
 * What this install is, and which version of it.
 *
 * The updater compares VERSION against a package's `requires.botex` and
 * against the archive's newest release, so this constant is the single
 * source of truth for "what am I running". A core update rewrites this
 * file along with the rest of src/, which is what makes the new version
 * visible immediately afterwards.
 */
final class Botex
{
    public const NAME = 'Botex';

    /**
     * Semantic version of the core.
     *
     * MUST be bumped in the same commit as any change to the published
     * core surface, or an install cannot tell whether it needs an update.
     */
    public const VERSION = '1.0.1';

    /** Lowest PHP this core is known to run on. */
    public const PHP_MINIMUM = '8.2.0';

    /**
     * Format version of the .botex package the updater can read.
     *
     * Bumped only for a breaking change to the archive layout, so an old
     * bot refuses a package it would misread rather than half-installing
     * it. See Botex\Archive\Manifest.
     */
    public const PACKAGE_FORMAT = 1;

    public static function version(): string
    {
        return self::VERSION;
    }

    /** e.g. "Botex 1.1.0 (PHP 8.3.28)" */
    public static function describe(): string
    {
        return self::NAME . ' ' . self::VERSION . ' (PHP ' . PHP_VERSION . ')';
    }
}
