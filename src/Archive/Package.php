<?php

namespace Botex\Archive;

/**
 * A .botex package: its manifest, its payload, and the checks that make it
 * safe to unpack.
 *
 * Nothing here writes to the install. A Package is the verified *content*
 * of a download; deciding what to do with it belongs to the updater, which
 * is what keeps "can I trust these bytes" separate from "should I replace
 * this folder".
 *
 * verify() is not optional in practice: open() runs it, so a Package that
 * exists at all has already had every payload file checked against the
 * hash its manifest pins.
 */
class Package
{
    public const EXTENSION = 'botex';

    private function __construct(
        public readonly Manifest $manifest,
        private Reader $reader
    ) {
    }

    /**
     * Opens and fully verifies a package file.
     *
     * @throws ArchiveException
     */
    public static function open(string $file): self
    {
        return self::fromReader(Zip::read($file));
    }

    /** @throws ArchiveException */
    public static function fromString(string $bytes): self
    {
        return self::fromReader(Zip::readString($bytes));
    }

    /** @throws ArchiveException */
    private static function fromReader(Reader $reader): self
    {
        if (!$reader->has(Manifest::FILE)) {
            throw new ArchiveException('Not a Botex package: botex.json is missing.');
        }

        $package = new self(
            Manifest::fromJson($reader->get(Manifest::FILE)),
            $reader
        );

        $package->verify();

        return $package;
    }

    /**
     * Checks the payload against the manifest, both ways.
     *
     * Both directions matter. A missing or altered file is the obvious
     * case; an *extra* file in the payload is the subtle one, since that is
     * how something unlisted -- and therefore unhashed and unreviewed --
     * would otherwise ride along into the install.
     *
     * @throws ArchiveException
     */
    public function verify(): void
    {
        $prefix = Manifest::PAYLOAD . '/';
        $present = [];

        foreach ($this->reader->names() as $name) {
            if ($name === Manifest::FILE) {
                continue;
            }

            if (!str_starts_with($name, $prefix)) {
                throw new ArchiveException(
                    "Package contains '{$name}', which is outside " . Manifest::PAYLOAD . '/.'
                );
            }

            $present[] = substr($name, strlen($prefix));
        }

        $missing = array_diff(array_keys($this->manifest->files), $present);

        if ($missing !== []) {
            throw new ArchiveException(
                'Package is missing files its manifest lists: ' . implode(', ', array_slice($missing, 0, 5))
                    . (count($missing) > 5 ? ' and ' . (count($missing) - 5) . ' more' : '')
            );
        }

        $unlisted = array_diff($present, array_keys($this->manifest->files));

        if ($unlisted !== []) {
            throw new ArchiveException(
                'Package contains files its manifest does not list: '
                    . implode(', ', array_slice($unlisted, 0, 5))
                    . (count($unlisted) > 5 ? ' and ' . (count($unlisted) - 5) . ' more' : '')
            );
        }

        foreach ($this->manifest->files as $path => $expected) {
            // Reader::get() has already verified the CRC, which catches
            // corruption. This catches substitution: a CRC is trivial to
            // forge, a sha256 is not.
            $actual = Hash::string($this->reader->get($prefix . $path));

            if (!Hash::matches($expected, $actual)) {
                throw new ArchiveException("Package file '{$path}' does not match its recorded hash.");
            }
        }
    }

    /**
     * One payload file's contents.
     *
     * @throws ArchiveException
     */
    public function file(string $path): string
    {
        return $this->reader->get(Manifest::PAYLOAD . '/' . Zip::path($path));
    }

    /** @return array<string> payload paths, relative to files/ */
    public function files(): array
    {
        return array_keys($this->manifest->files);
    }

    /**
     * Unpacks the payload into a directory.
     *
     * @return array<string> paths written
     *
     * @throws ArchiveException
     */
    public function extractTo(string $destination): array
    {
        return $this->reader->extractTo($destination, Manifest::PAYLOAD);
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function version(): string
    {
        return $this->manifest->version;
    }

    public function isCore(): bool
    {
        return $this->manifest->isCore();
    }

    /** Uncompressed payload size in bytes. */
    public function size(): int
    {
        return $this->reader->size();
    }

    /**
     * Builds a package from a folder on disk. Used by the hub to publish.
     *
     * @param  string        $directory folder to pack
     * @param  callable|null $filter    fn(string $relative): bool
     *
     * @throws ArchiveException
     */
    public static function build(
        string $directory,
        string $type,
        string $slug,
        string $name,
        string $version,
        string $description = '',
        array $requires = [],
        array $commands = [],
        string $changelog = '',
        string $readme = '',
        ?callable $filter = null,
        array $extra = []
    ): string {
        $hashes = Hash::directory($directory, $filter);

        if ($hashes === []) {
            throw new ArchiveException("Nothing to package in {$directory}");
        }

        $manifest = Manifest::create(
            type: $type,
            slug: $slug,
            name: $name,
            version: $version,
            description: $description,
            files: $hashes,
            requires: $requires,
            commands: $commands === [] ? Manifest::defaultCommands($type, $slug) : $commands,
            changelog: $changelog,
            readme: $readme,
            extra: $extra
        );

        $writer = Zip::writer();
        $writer->add(Manifest::FILE, $manifest->toJson());
        $writer->addDirectory($directory, Manifest::PAYLOAD, $filter);

        return $writer->toString();
    }

    /** The conventional file name for a published package. */
    public static function filename(string $slug, string $version): string
    {
        return $slug . '-' . $version . '.' . self::EXTENSION;
    }
}
