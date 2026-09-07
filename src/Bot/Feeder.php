<?php

namespace Botex\Bot;

class Feeder
{
    private array $instances = [];

    /**
     * The container this process booted with.
     *
     * Only for code with nowhere to inject: a Keyboard is built with a
     * static constructor from dozens of call sites, so a button carrying a
     * Run action has no other way to reach the binder. Everything else
     * takes what it needs through its constructor.
     */
    private static ?self $instance = null;

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** Called by the bootstrap, once, for the container it returns. */
    public function share(): void
    {
        self::$instance = $this;
    }

    public function set(string $class, object $instance): void
    {
        $this->instances[$class] = $instance;
    }

    public function get(string $class): object
    {
        if (!isset($this->instances[$class])) {
            throw new \RuntimeException(
                "Instance of {$class} not found in Feeder."
            );
        }

        return $this->instances[$class];
    }

    public function make(string $class): object
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(
                "{$class} is not instantiable."
            );
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            $instance = new $class();

            $this->instances[$class] = $instance;

            return $instance;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (
                !$type instanceof \ReflectionNamedType ||
                $type->isBuiltin()
            ) {
                throw new \RuntimeException(
                    "Cannot resolve {$parameter->getName()} in {$class}"
                );
            }

            $dependencies[] = $this->make(
                $type->getName()
            );
        }

        $instance = $reflection->newInstanceArgs(
            $dependencies
        );

        $this->instances[$class] = $instance;

        return $instance;
    }
}