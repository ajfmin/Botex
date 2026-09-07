<?php

namespace Botex\Bot\Conversation;

use Botex\Extension\Registry;
use Botex\Support\Log\Logger;

/**
 * Allowlist of runnable flows, keyed by name.
 *
 * A session stores only a flow name. Resolving that name here, against
 * classes contributed by loaded code, means a tampered database row can
 * never cause an arbitrary class to be instantiated.
 */
class Flows
{
    /** @var array<class-string<FlowInterface>> */
    private const CORE = [
        \Botex\Bot\Admin\Flow\ManageUserFlow::class,
    ];

    /** @var array<string, class-string<FlowInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions,
        private Logger $log
    ) {
    }

    /** @return array<string, class-string<FlowInterface>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        foreach ([...self::CORE, ...$this->extensions->flows()] as $class) {
            if (!is_subclass_of($class, FlowInterface::class)) {
                $this->log->warning(
                    "Flow {$class} does not implement FlowInterface; skipped.",
                    ['handler' => $class]
                );
                continue;
            }

            // core first, so an extension cannot displace a core flow
            $this->map[$class::name()] ??= $class;
        }

        return $this->map;
    }

    /** @return class-string<FlowInterface>|null */
    public function find(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }
}
