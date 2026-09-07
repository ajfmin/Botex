<?php

namespace Botex\Bot\Action;

/**
 * What a button should do when pressed.
 *
 * Two kinds of target, and the difference is only in what gets resolved
 * at press time:
 *
 *   Run::command('help')              runs the /help command
 *   Run::extension('show_time', $d)   runs one of an extension's runnables
 *   Run::core('confirm', $d)          runs a runnable contributed by core
 *
 * This is the intent, not the stored row. It carries no token and knows
 * nothing about Telegram; [[ActionBinder]] persists it and mints the token
 * that actually travels. See [[ActionStore]] for the row it becomes.
 */
class Run
{
    /**
     * Reserved slug for targets that belong to core rather than an
     * extension, so the same mechanism serves both.
     */
    public const CORE = 'core';

    /** A ttl of zero means the action never expires on its own. */
    public const NO_EXPIRY = 0;

    /** Resolved through the command allowlist, by verb. */
    public const COMMAND = 'command';

    /** Resolved through the runnable allowlist, by extension and name. */
    public const ACTION = 'action';

    /**
     * @param string               $target    self::COMMAND or self::ACTION
     * @param string               $extension extension slug, or self::CORE
     * @param string               $name      verb without its slash, or a
     *                                        runnable's name()
     * @param array<string, mixed> $data
     * @param int|null             $ttl       seconds; null defers to config
     */
    private function __construct(
        public readonly string $target,
        public readonly string $extension,
        public readonly string $name,
        public readonly array $data = [],
        public readonly ?int $ttl = null,
        public readonly bool $once = false
    ) {
    }

    /**
     * Runs a command, exactly as if the user had typed it.
     *
     * The verb is resolved through the same allowlist the router uses, so
     * a button cannot reach anything typing could not, and a command from
     * a disabled extension resolves to nothing.
     *
     * Arguments are positional, because that is what a command line is:
     * Run::command('remind', [30]) presses as "/remind 30". A command
     * reads them from the text it is handed, the same as always.
     *
     * @param string                    $command   '/help' or 'help'
     * @param array<int, string|int|float|bool> $arguments
     */
    public static function command(string $command, array $arguments = []): self
    {
        return new self(
            self::COMMAND,
            self::CORE,
            self::validName(ltrim(trim($command), '/'), 'command'),
            self::arguments($arguments)
        );
    }

    /**
     * Runs one of an extension's named runnables.
     *
     * The slug is taken from the calling class's namespace, so a class
     * under Extensions\Clock\ addresses its own actions without repeating
     * 'Clock' at every call site. Pass $slug explicitly from anywhere
     * else, or to target another extension on purpose.
     *
     * @param string               $action the runnable's name()
     * @param array<string, mixed> $data   anything json-encodable
     */
    public static function extension(string $action, array $data = [], ?string $slug = null): self
    {
        return new self(
            self::ACTION,
            self::validName($slug ?? self::callingSlug(), 'extension slug'),
            self::validName($action, 'action name'),
            $data
        );
    }

    /**
     * Runs a runnable contributed by core rather than an extension.
     *
     * @param array<string, mixed> $data
     */
    public static function core(string $action, array $data = []): self
    {
        return new self(
            self::ACTION,
            self::CORE,
            self::validName($action, 'action name'),
            $data
        );
    }

    /** Replaces the data wholesale. */
    public function with(array $data): self
    {
        return new self(
            $this->target,
            $this->extension,
            $this->name,
            $this->target === self::COMMAND ? self::arguments($data) : $data,
            $this->ttl,
            $this->once
        );
    }

    /** Adds or overwrites one key, for building an action up in steps. */
    public function set(string $key, mixed $value): self
    {
        if ($this->target === self::COMMAND) {
            throw new \InvalidArgumentException(
                'A command target takes positional arguments, not keys. '
                . 'Use Run::command(..., [$a, $b]) or Run::extension() for structured data.'
            );
        }

        return $this->with(array_merge($this->data, [$key => $value]));
    }

    public function expiresIn(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('An action ttl cannot be negative.');
        }

        return new self(
            $this->target,
            $this->extension,
            $this->name,
            $this->data,
            $seconds,
            $this->once
        );
    }

    /**
     * Keeps the action until it is used or pruned.
     *
     * Use sparingly: a button in an old message stays live indefinitely.
     */
    public function never(): self
    {
        return $this->expiresIn(self::NO_EXPIRY);
    }

    /**
     * Marks the action as single-use, so a double tap runs it once.
     *
     * The right choice for anything with a side effect, e.g. charging a
     * wallet. Reusable is the default, which suits a refresh button.
     */
    public function once(bool $once = true): self
    {
        return new self(
            $this->target,
            $this->extension,
            $this->name,
            $this->data,
            $this->ttl,
            $once
        );
    }

    public function isCommand(): bool
    {
        return $this->target === self::COMMAND;
    }

    public function isAction(): bool
    {
        return $this->target === self::ACTION;
    }

    public function isCore(): bool
    {
        return $this->extension === self::CORE;
    }

    /** How an action target is addressed in the Runnables allowlist. */
    public function key(): string
    {
        return $this->extension . ':' . $this->name;
    }

    /**
     * The text a command target presses as, e.g. "/remind 30".
     *
     * Rebuilt from the stored verb and arguments rather than stored whole,
     * so nothing that reaches the router was ever a free-form string.
     */
    public function commandText(): string
    {
        $arguments = $this->data['args'] ?? [];
        $text = '/' . $this->name;

        return is_array($arguments) && $arguments !== []
            ? $text . ' ' . implode(' ', array_map('strval', $arguments))
            : $text;
    }

    /**
     * Normalises command arguments to a list of scalars.
     *
     * A command reads a line of text, so anything shaped like structured
     * data would be silently dropped. Rejected here rather than lost.
     *
     * @return array{args: array<int, string>}
     */
    private static function arguments(array $arguments): array
    {
        if (array_key_exists('args', $arguments) && is_array($arguments['args'])) {
            $arguments = $arguments['args'];
        }

        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException(
                'Command arguments must be a positional list. '
                . 'Use Run::extension() when you need keyed data.'
            );
        }

        $values = [];

        foreach ($arguments as $argument) {
            if (!is_scalar($argument)) {
                throw new \InvalidArgumentException(
                    'Command arguments must be scalars; they become words in a command line.'
                );
            }

            $value = is_bool($argument) ? ($argument ? '1' : '0') : (string) $argument;

            // A space would split one argument into two on the way back
            // through the router, which is not what the caller asked for.
            if (preg_match('/\s/', $value)) {
                throw new \InvalidArgumentException(
                    "Command argument '{$value}' cannot contain whitespace."
                );
            }

            $values[] = $value;
        }

        return ['args' => $values];
    }

    /**
     * The extension slug of whoever called, from its namespace.
     *
     * Only Extensions\<Slug>\... can be inferred, which is exactly the
     * case worth inferring; anything else has to say which slug it means.
     */
    private static function callingSlug(): string
    {
        // Frame 1 is the caller of the static factory, frame 0 being the
        // factory itself.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $class = $frames[2]['class'] ?? $frames[1]['class'] ?? '';
        $parts = explode('\\', (string) $class);

        if (count($parts) >= 2 && $parts[0] === 'Extensions') {
            return $parts[1];
        }

        throw new \InvalidArgumentException(
            'Run::extension() can only infer the slug inside an Extensions\\<Slug> class'
            . ($class === '' ? '.' : ", not in {$class}.")
            . ' Pass it explicitly: Run::extension($action, $data, \'Slug\').'
        );
    }

    /**
     * Slugs and names end up in a lookup key joined by a colon, so a colon
     * in either would make that key ambiguous. Rejected here, at build
     * time, rather than at press time.
     */
    private static function validName(string $value, string $label): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \InvalidArgumentException(
                "Invalid {$label} '{$value}': use letters, digits, dot, underscore or dash."
            );
        }

        return $value;
    }
}
