<?php

namespace Botex\Extension;

/**
 * Builds Settings objects for extensions.
 *
 * Extensions cannot take Settings in a constructor, since it needs a
 * slug that reflection cannot supply. They take this factory instead.
 */
class SettingsFactory
{
    /** @var array<string, Settings> */
    private array $cache = [];

    public function __construct(
        private Registry $registry,
        private string $storagePath
    ) {
    }

    public function for(string $slug): Settings
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $manifest = $this->registry->find($slug);

        if (!$manifest) {
            throw new \RuntimeException("Extension '{$slug}' was not found.");
        }

        return $this->cache[$slug] = new Settings(
            slug: $slug,
            extensionPath: $manifest->path,
            storagePath: $this->storagePath
        );
    }
}
