<?php

namespace Botex\Bot\Action;

use Botex\Extension\Registry;
use Botex\Support\Log\Logger;

/**
 * Allowlist of runnable actions, keyed by "extension:action".
 *
 * A stored row holds only those two names. Resolving them here, against
 * classes contributed by loaded code, means a tampered database row can
 * never cause an arbitrary class to be instantiated. It is also why a
 * disabled extension resolves to nothing: the Registry only reports what
 * is enabled, so no extension-specific routing is needed.
 */
class Runnables
{
    /**
     * Runnables that ship with core, addressed under Run::CORE.
     *
     * @var array<class-string<RunnableInterface>>
     */
    private const CORE = [];

    /** @var array<string, class-string<RunnableInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions,
        private Logger $log
    ) {
    }

    /** @return array<string, class-string<RunnableInterface>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        $grouped = [Run::CORE => self::CORE] + $this->extensions->runnables();

        foreach ($grouped as $slug => $classes) {
            foreach ($classes as $class) {
                if (!is_subclass_of($class, RunnableInterface::class)) {
                    $this->log->warning(
                        "Runnable {$class} does not implement RunnableInterface; skipped.",
                        ['extension' => $slug, 'handler' => $class]
                    );
                    continue;
                }

                // core first, so an extension cannot displace a core action
                $this->map[$slug . ':' . $class::name()] ??= $class;
            }
        }

        return $this->map;
    }

    /** @return class-string<RunnableInterface>|null */
    public function find(string $extension, string $action): ?string
    {
        return $this->all()[$extension . ':' . $action] ?? null;
    }

    /** @return class-string<RunnableInterface>|null */
    public function findByKey(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether an action can be minted right now.
     *
     * ActionBinder calls this so a typo surfaces where the button is
     * built, not silently when a user presses it.
     */
    public function exists(string $extension, string $action): bool
    {
        return $this->find($extension, $action) !== null;
    }

    /**
     * Action names available under one extension.
     *
     * @return array<int, string>
     */
    public function forExtension(string $extension): array
    {
        $prefix = $extension . ':';
        $names = [];

        foreach (array_keys($this->all()) as $key) {
            if (str_starts_with($key, $prefix)) {
                $names[] = substr($key, strlen($prefix));
            }
        }

        return $names;
    }
}
