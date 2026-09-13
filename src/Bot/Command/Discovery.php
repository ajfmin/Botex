<?php

namespace Botex\Bot\Command;

/**
 * Finds the commands an operator wrote next to the shipped ones.
 *
 * A bot almost always needs a handful of commands that are specific to it
 * -- /profile, /support, whatever that bot is for -- and wrapping each in
 * an extension, with a manifest and an entry class, is more ceremony than
 * the job deserves. Dropping a class in this directory is the short path;
 * extensions remain the right shape for anything modular or installable.
 *
 * Discovery is by file name, because that is what PSR-4 already promises:
 * Profile.php in this directory is Botex\Bot\Command\Profile, and if it is
 * not -- a different namespace, a differently named class -- the class
 * simply does not exist and the file is skipped. Nothing here reads or
 * parses source; it asks the autoloader, exactly as the router does.
 *
 * What comes back is class names only. They are instantiated where every
 * other handler is, through the Feeder, so a custom command gets the same
 * constructor injection a core one does.
 *
 * The core's own commands are never returned: which of those are
 * registered is the core's decision, and Unknown -- a real command class
 * that is deliberately not typeable -- is the reason that distinction
 * matters.
 */
class Discovery
{
    /**
     * Files in this directory that are core machinery rather than a
     * command an operator could write, or a command core registers itself.
     *
     * Kept as base names rather than class names so the check costs
     * nothing and needs no autoloading to decide.
     *
     * @var array<string>
     */
    private const SHIPPED = [
        'Admin',
        'Cancel',
        'CommandInterface',
        'Commands',
        'Discovery',
        'Start',
        'Unknown',
        'Wallet',
    ];

    /** @var array<class-string<CommandInterface>>|null */
    private ?array $found = null;

    /**
     * Where to look: this file's own folder, which is the one operators
     * are told to use.
     *
     * Not a constructor argument, because the Feeder autowires class-typed
     * parameters only -- a string would make this class unresolvable, and
     * bending the container for one scalar is a worse trade than a named
     * constructor for the one caller that needs another directory.
     */
    private string $directory = __DIR__;

    /** Looks somewhere else. For tests, which have no src/Bot/Command/. */
    public static function in(string $directory): self
    {
        $discovery = new self();
        $discovery->directory = rtrim($directory, '/\\');

        return $discovery;
    }

    /**
     * Custom command classes, in a stable order.
     *
     * @return array<class-string<CommandInterface>>
     */
    public function commands(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $this->found = [];

        foreach ($this->candidates() as $name) {
            $class = __NAMESPACE__ . '\\' . $name;

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            // Abstract classes, interfaces and traits are not something the
            // dispatcher can instantiate; a class that does not implement
            // the contract is not a command at all.
            if (!$reflection->isInstantiable() || !$reflection->implementsInterface(CommandInterface::class)) {
                continue;
            }

            $this->found[] = $class;
        }

        return $this->found;
    }

    /**
     * Base names worth asking the autoloader about.
     *
     * @return array<string>
     */
    private function candidates(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $names = [];

        foreach ((array) scandir($this->directory) as $entry) {
            $entry = (string) $entry;

            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $name = substr($entry, 0, -4);

            if (in_array($name, self::SHIPPED, true)) {
                continue;
            }

            // Anything PSR-4 could not have produced: a class name is what
            // the file name has to be, so a file that is not one cannot
            // hold a class the autoloader would find here.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }
}
