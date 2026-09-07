<?php

namespace Botex\Bot\Command;

use Botex\Extension\Registry;

/**
 * Allowlist of commands, keyed by verb.
 *
 * Extracted from Router so a Run button can resolve a verb through the
 * exact list typing resolves against: one allowlist, so a button can never
 * reach a command the user could not have typed, and a command from a
 * disabled extension resolves to nothing.
 *
 * The same shape as [[Runnables]], [[Jobs]] and [[Flows]], and for the same
 * reason: a stored row holds a name, never a class.
 */
class Commands
{
    /**
     * Commands that ship with core.
     *
     * @var array<class-string<CommandInterface>>
     */
    private const CORE = [
        Start::class,
        Admin::class,
        Wallet::class,
        Cancel::class,
    ];

    /** @var array<class-string<CommandInterface>>|null */
    private ?array $list = null;

    /** @var array<string, class-string<CommandInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions
    ) {
    }

    /**
     * Every command, core first, so an extension cannot shadow /start.
     *
     * @return array<class-string<CommandInterface>>
     */
    public function all(): array
    {
        return $this->list ??= [...self::CORE, ...$this->extensions->commands()];
    }

    /**
     * Verb to class, core winning any collision.
     *
     * @return array<string, class-string<CommandInterface>>
     */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        foreach ($this->all() as $class) {
            $verb = ltrim(trim($class::command()), '/');

            if ($verb === '') {
                continue;
            }

            // core first, so an extension cannot displace a core verb
            $this->map[$verb] ??= $class;
        }

        return $this->map;
    }

    /**
     * Resolves a verb, with or without its slash.
     *
     * @return class-string<CommandInterface>|null
     */
    public function find(string $verb): ?string
    {
        return $this->map()[ltrim(trim($verb), '/')] ?? null;
    }

    public function exists(string $verb): bool
    {
        return $this->find($verb) !== null;
    }
}
