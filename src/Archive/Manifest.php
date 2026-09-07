<?php

namespace Botex\Archive;

use Botex\Botex;

/**
 * The botex.json inside a package: what it is, what it needs, and what it
 * should contain.
 *
 * Parsed and validated in one place so the bot and the hub cannot disagree
 * about what a valid package looks like -- the hub refuses to publish
 * anything this rejects, and the bot refuses to install it, from the same
 * code.
 *
 * The `files` map is the reason an update can be trusted: it pins a sha256
 * per path, so a package whose payload was altered after signing fails
 * before a single file is written.
 */
class Manifest
{
    public const FILE = 'botex.json';

    /** Where the payload lives inside the archive. */
    public const PAYLOAD = 'files';

    public const TYPE_EXTENSION = 'extension';
    public const TYPE_CORE = 'core';

    /**
     * @param string $type            extension|core
     * @param string $slug            folder name for an extension, 'core' for core
     * @param array<string,string> $files    path inside files/ => sha256
     * @param array<string,string> $requires botex|php => constraint
     * @param array<string,string> $commands install|update|remove => CLI line
     * @param array<string,mixed>  $extra    anything the hub adds; not interpreted here
     */
    private function __construct(
        public readonly string $type,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly array $files,
        public readonly array $requires,
        public readonly array $commands,
        public readonly string $changelog,
        public readonly string $readme,
        public readonly int $format,
        public readonly array $extra
    ) {
    }

    /**
     * Builds a manifest for publishing.
     *
     * @throws ArchiveException
     */
    public static function create(
        string $type,
        string $slug,
        string $name,
        string $version,
        string $description = '',
        array $files = [],
        array $requires = [],
        array $commands = [],
        string $changelog = '',
        string $readme = '',
        array $extra = []
    ): self {
        $manifest = new self(
            type: $type,
            slug: $slug,
            name: $name,
            version: $version,
            description: $description,
            files: $files,
            requires: $requires,
            commands: $commands,
            changelog: $changelog,
            readme: $readme,
            format: Botex::PACKAGE_FORMAT,
            extra: $extra
        );

        $manifest->validate();

        return $manifest;
    }

    /** @throws ArchiveException */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ArchiveException('botex.json is not valid JSON.');
        }

        return self::fromArray($data);
    }

    /** @throws ArchiveException */
    public static function fromArray(array $data): self
    {
        $files = [];

        // Normalised through Zip::path() so the keys here are spelled the
        // same way the archive spells its entries; otherwise a manifest
        // could pin "./a.php" while the archive holds "a.php" and the two
        // would never match.
        foreach ((array) ($data['files'] ?? []) as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                throw new ArchiveException('botex.json has a malformed files entry.');
            }

            $files[Zip::path($path)] = strtolower($hash);
        }

        $manifest = new self(
            type: (string) ($data['type'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            files: $files,
            requires: array_map('strval', array_filter(
                (array) ($data['requires'] ?? []),
                'is_scalar'
            )),
            commands: array_map('strval', array_filter(
                (array) ($data['commands'] ?? []),
                'is_scalar'
            )),
            changelog: is_string($data['changelog'] ?? null) ? $data['changelog'] : '',
            readme: is_string($data['readme'] ?? null) ? $data['readme'] : '',
            format: (int) ($data['format'] ?? 1),
            extra: is_array($data['extra'] ?? null) ? $data['extra'] : []
        );

        $manifest->validate();

        return $manifest;
    }

    /**
     * Every rule a package must satisfy.
     *
     * @throws ArchiveException
     */
    public function validate(): void
    {
        if (!in_array($this->type, [self::TYPE_EXTENSION, self::TYPE_CORE], true)) {
            throw new ArchiveException("Unknown package type '{$this->type}'.");
        }

        // A newer format than this core understands. Refusing outright beats
        // reading it optimistically: the whole point of the field is that a
        // future layout change cannot be half-applied by an old bot.
        if ($this->format > Botex::PACKAGE_FORMAT) {
            throw new ArchiveException(
                "Package format {$this->format} is newer than this Botex understands "
                    . '(' . Botex::PACKAGE_FORMAT . '). Update the core first.'
            );
        }

        // The slug becomes a folder name and travels inside colon-joined
        // allowlist keys, so it is held to the same rule the extension
        // system already enforces.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $this->slug) !== 1) {
            throw new ArchiveException("Invalid package slug '{$this->slug}'.");
        }

        if (str_starts_with($this->slug, '.')) {
            throw new ArchiveException("Package slug '{$this->slug}' may not start with a dot.");
        }

        if ($this->type === self::TYPE_CORE && $this->slug !== self::TYPE_CORE) {
            throw new ArchiveException("A core package must use the slug 'core'.");
        }

        if ($this->type === self::TYPE_EXTENSION && strtolower($this->slug) === self::TYPE_CORE) {
            throw new ArchiveException("'core' is reserved and cannot be an extension slug.");
        }

        if ($this->name === '') {
            throw new ArchiveException('botex.json needs a name.');
        }

        if (!Version::isValid($this->version)) {
            throw new ArchiveException("Invalid version '{$this->version}'.");
        }

        if ($this->files === []) {
            throw new ArchiveException('botex.json lists no files.');
        }

        foreach ($this->files as $path => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new ArchiveException("botex.json has a bad hash for '{$path}'.");
            }
        }

        foreach (array_keys($this->requires) as $key) {
            if (!in_array($key, ['botex', 'php'], true)) {
                throw new ArchiveException("botex.json requires an unknown thing: '{$key}'.");
            }
        }

        // An extension must carry the two files the registry looks for by
        // name, or it would install into a folder the Registry then skips.
        if ($this->type === self::TYPE_EXTENSION) {
            foreach (['extension.json', 'Extension.php'] as $required) {
                if (!isset($this->files[$required])) {
                    throw new ArchiveException("An extension package must contain {$required}.");
                }
            }
        }
    }

    /**
     * Whether this package can run here.
     *
     * @return array<string> unmet requirements, empty when satisfied
     */
    public function unmet(?string $coreVersion = null, ?string $phpVersion = null): array
    {
        $problems = [];

        $core = $coreVersion ?? Botex::VERSION;
        $php = $phpVersion ?? PHP_VERSION;

        if (isset($this->requires['botex']) && !Version::satisfies($core, $this->requires['botex'])) {
            $problems[] = "needs Botex {$this->requires['botex']}, this is {$core}";
        }

        if (isset($this->requires['php']) && !Version::satisfies($php, $this->requires['php'])) {
            $problems[] = "needs PHP {$this->requires['php']}, this is {$php}";
        }

        return $problems;
    }

    public function isCore(): bool
    {
        return $this->type === self::TYPE_CORE;
    }

    public function isExtension(): bool
    {
        return $this->type === self::TYPE_EXTENSION;
    }

    /** The install/update/remove line the website shows, if the hub set one. */
    public function command(string $which): string
    {
        return $this->commands[$which] ?? '';
    }

    /**
     * Default CLI lines for a package, used when the hub does not override
     * them. Kept here so the website and the console never drift apart.
     *
     * @return array<string,string>
     */
    public static function defaultCommands(string $type, string $slug): array
    {
        if ($type === self::TYPE_CORE) {
            return [
                'install' => 'php bin/console core:update',
                'update' => 'php bin/console core:update',
                'remove' => '',
            ];
        }

        return [
            'install' => "php bin/console ext:install {$slug}",
            'update' => "php bin/console ext:update {$slug}",
            'remove' => "php bin/console ext:remove {$slug}",
        ];
    }

    public function toArray(): array
    {
        return array_filter([
            'format' => $this->format,
            'type' => $this->type,
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'requires' => $this->requires,
            'commands' => $this->commands,
            'changelog' => $this->changelog,
            'readme' => $this->readme,
            'files' => $this->files,
            'extra' => $this->extra,
        ], static fn ($value) => $value !== '' && $value !== []);
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Metadata without the file map or the long text.
     *
     * What the hub's index and the bot's "available updates" view need; the
     * files map is the bulk of a manifest and neither has a use for it.
     */
    public function summary(): array
    {
        return array_filter([
            'type' => $this->type,
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'requires' => $this->requires,
            'commands' => $this->commands,
        ], static fn ($value) => $value !== '' && $value !== []);
    }
}
