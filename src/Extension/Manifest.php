<?php

namespace Botex\Extension;

/**
 * Parsed extension.json. The slug always comes from the folder name,
 * never from the file, so it cannot disagree with the autoload path.
 */
class Manifest
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $entry,
        public readonly string $path
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
            path: $path
        );
    }
}
