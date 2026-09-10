<?php

namespace Botex\Extension;

/**
 * Parsed extension.json. The slug always comes from the folder name,
 * never from the file, so it cannot disagree with the autoload path.
 */
class Manifest
{
    /**
     * @param array<string, string> $requires slug => constraint, from
     *                                       `requires.extensions`
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $entry,
        public readonly string $path,
        public readonly array $requires = []
    ) {
    }

    public static function fromPath(string $path): self
    {
        $slug = basename($path);
        $file = $path . '/extension.json';

        if (!is_file($file)) {
            throw new \RuntimeException("{$slug}: extension.json is missing.");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new \RuntimeException("{$slug}: extension.json is not valid JSON.");
        }

        foreach (['name', 'version', 'entry'] as $key) {
            if (empty($data[$key]) || !is_string($data[$key])) {
                throw new \RuntimeException("{$slug}: extension.json needs a '{$key}'.");
            }
        }

        return new self(
            slug: $slug,
            name: $data['name'],
            version: $data['version'],
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            entry: $data['entry'],
            path: $path,
            requires: self::requiredExtensions($slug, $data)
        );
    }

    /** Whether this extension needs another one installed and enabled. */
    public function requiresExtension(string $slug): bool
    {
        return isset($this->requires[$slug]);
    }

    /**
     * The `requires.extensions` map, cleaned up.
     *
     * `requires` also carries `php` and `botex`, which are the archive's
     * business and are checked when a package is installed. Only the
     * `extensions` key means anything to a bot that is already running,
     * so only that one is read here -- and a malformed entry is dropped
     * rather than allowed to refuse an install for a reason nobody can
     * read.
     *
     * @param  array<mixed> $data
     * @return array<string, string>
     */
    private static function requiredExtensions(string $slug, array $data): array
    {
        $required = [];
        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        $extensions = is_array($requires['extensions'] ?? null) ? $requires['extensions'] : [];

        foreach ($extensions as $name => $constraint) {
            if (!is_string($name) || trim($name) === '' || $name === $slug) {
                continue;
            }

            $required[trim($name)] = is_string($constraint) && trim($constraint) !== ''
                ? trim($constraint)
                : '*';
        }

        return $required;
    }
}
