<?php

namespace Botex\Archive;

/**
 * A zip reader and writer in plain PHP, over zlib.
 *
 * ZipArchive is a separate extension and is missing from plenty of PHP
 * builds -- including the one this was developed against, and a fair share
 * of shared hosting. Since both ends of the archive (the bot pulling an
 * update and the hub publishing one) have to agree byte for byte, the
 * format is implemented here rather than depending on something that may
 * not be installed. Only zlib is needed, which is compiled in almost
 * everywhere and is already required by composer's own installer.
 *
 * Scope is deliberately the subset a package needs: deflate and store, no
 * encryption, no zip64, no multi-disk. A package that needs more than
 * 65535 files or 4 GB is not a package.
 *
 * SECURITY: everything read here came off the network, so extraction is
 * the dangerous half. Paths are validated before any handle is opened --
 * see path() and Reader::extractTo() -- because "unpack an archive" is the
 * classic way to be handed a write to ../../.env.
 */
class Zip
{
    /** Local file header, central directory entry, end of central directory. */
    private const LOCAL = "PK\x03\x04";
    private const CENTRAL = "PK\x01\x02";
    private const EOCD = "PK\x05\x06";

    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    /** Zip's own ceilings. Hitting either means the caller is misusing this. */
    private const MAX_ENTRIES = 65535;
    private const MAX_SIZE = 4294967295;

    /**
     * Largest single entry we will inflate, and largest total we will
     * write out.
     *
     * A zip can claim a small compressed size and expand to something that
     * fills the disk (a "zip bomb"), so the declared size is checked
     * against these before inflating and the inflated result is checked
     * again after.
     */
    public const MAX_ENTRY_BYTES = 67108864;      // 64 MB per file
    public const MAX_TOTAL_BYTES = 268435456;     // 256 MB per archive

    /**
     * Normalises a path for storage in the archive, or throws.
     *
     * Called on the way in and on the way out, so a hand-crafted archive
     * cannot smuggle a path past the writer's validation by being built
     * elsewhere.
     *
     * @throws ArchiveException
     */
    public static function path(string $path): string
    {
        // Zip stores forward slashes regardless of the producing platform,
        // and a backslash on Windows is a separator, so both collapse to
        // one form before anything is judged.
        $normalised = str_replace('\\', '/', trim($path));

        if ($normalised === '') {
            throw new ArchiveException('Archive entry has an empty path.');
        }

        // A leading slash would extract to the filesystem root.
        if (str_starts_with($normalised, '/')) {
            throw new ArchiveException("Archive entry '{$path}' is an absolute path.");
        }

        // C:\ and friends, same problem.
        if (preg_match('/^[A-Za-z]:/', $normalised) === 1) {
            throw new ArchiveException("Archive entry '{$path}' is an absolute path.");
        }

        // Checked segment by segment rather than with str_contains('..'),
        // so a legitimate name like "..dotfile" or "a..b" is not rejected
        // while "a/../../b" still is.
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                throw new ArchiveException("Archive entry '{$path}' escapes the archive root.");
            }
        }

        // A NUL truncates the name in any C-level filesystem call, so
        // "safe.txt\0../../evil" would validate here and write there.
        if (str_contains($normalised, "\0")) {
            throw new ArchiveException("Archive entry '{$path}' contains a null byte.");
        }

        // Collapse "./" and any doubled slashes, which are noise that would
        // otherwise produce two spellings of the same entry.
        $parts = array_values(array_filter(
            explode('/', $normalised),
            static fn (string $part): bool => $part !== '' && $part !== '.'
        ));

        if ($parts === []) {
            throw new ArchiveException("Archive entry '{$path}' resolves to nothing.");
        }

        return implode('/', $parts);
    }

    public static function writer(): Writer
    {
        return new Writer();
    }

    /** @throws ArchiveException */
    public static function read(string $file): Reader
    {
        return Reader::open($file);
    }

    /** @throws ArchiveException */
    public static function readString(string $bytes): Reader
    {
        return Reader::fromString($bytes);
    }

    /** Whether deflate is usable; store-only is a poor but valid fallback. */
    public static function canDeflate(): bool
    {
        return function_exists('gzdeflate') && function_exists('gzinflate');
    }

    /**
     * DOS date and time, as zip has recorded timestamps since 1989.
     *
     * Zip predates unix timestamps in its header, so the two 16-bit fields
     * are packed by hand. Anything before 1980 is not representable and is
     * clamped, which only affects a clock that is badly wrong.
     */
    public static function dosTime(int $timestamp): array
    {
        $parts = getdate($timestamp);

        if ($parts['year'] < 1980) {
            return [0x21, 0]; // 1980-01-01 00:00:00
        }

        return [
            (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | ($parts['seconds'] >> 1),
        ];
    }

    public static function maxEntries(): int
    {
        return self::MAX_ENTRIES;
    }

    public static function maxSize(): int
    {
        return self::MAX_SIZE;
    }

    public static function methodStore(): int
    {
        return self::METHOD_STORE;
    }

    public static function methodDeflate(): int
    {
        return self::METHOD_DEFLATE;
    }

    public static function signatureLocal(): string
    {
        return self::LOCAL;
    }

    public static function signatureCentral(): string
    {
        return self::CENTRAL;
    }

    public static function signatureEocd(): string
    {
        return self::EOCD;
    }
}
