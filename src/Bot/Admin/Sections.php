<?php

namespace Botex\Bot\Admin;

use Botex\Extension\Registry;
use Botex\Support\Log\Logger;

/**
 * Allowlist of admin panel sections, keyed by their key().
 *
 * Same shape as the Flows registry: callback data carries a key, and
 * only keys resolved here can be dispatched.
 */
class Sections
{
    /** @var array<class-string<AdminSectionInterface>> */
    private const CORE = [
        Section\Stats::class,
        Section\Users::class,
        Section\Extensions::class,
        Section\Updates::class,
    ];

    /** @var array<string, class-string<AdminSectionInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions,
        private Logger $log
    ) {
    }

    /** @return array<string, class-string<AdminSectionInterface>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        foreach ([...self::CORE, ...$this->extensions->adminSections()] as $class) {
            if (!is_subclass_of($class, AdminSectionInterface::class)) {
                $this->log->warning(
                    "Admin section {$class} does not implement AdminSectionInterface; skipped.",
                    ['handler' => $class]
                );
                continue;
            }

            $key = $class::key();

            // a colon would break the admin:s:<key> callback format
            if ($key === '' || str_contains($key, ':')) {
                $this->log->warning(
                    "Admin section {$class} has an invalid key; skipped.",
                    ['handler' => $class, 'key' => $key]
                );
                continue;
            }

            // core first, so an extension cannot replace a core section
            $this->map[$key] ??= $class;
        }

        return $this->map;
    }

    /** @return class-string<AdminSectionInterface>|null */
    public function find(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }
}
