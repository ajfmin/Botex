<?php

namespace Botex\Update;

/**
 * What an update would do, worked out before anything is written.
 *
 * Every mutating command builds one of these first and can print it
 * (`--dry-run`) instead of applying it. That ordering is the point: the
 * decision to refuse a conflict is made against a complete picture, not
 * discovered halfway through writing files.
 */
class Plan
{
    public const ADD = 'add';
    public const REPLACE = 'replace';
    public const IDENTICAL = 'identical';
    public const DELETE = 'delete';
    public const CONFLICT = 'conflict';
    public const BLOCKED = 'blocked';

    /**
     * A file the operator owns, at a path the release wants to introduce.
     *
     * Different from a conflict: a conflict is an edited *core* file, and
     * --force resolves it by saying "discard my edit". This is a file the
     * core never shipped, so there is no core version to fall back to and
     * nothing --force could mean except "delete something of mine". The
     * operator renames one of the two; the updater does not choose.
     */
    public const COLLISION = 'collision';

    /** @var array<string, array{action:string, path:string, note:string}> */
    private array $entries = [];

    /** @var array<string> */
    private array $problems = [];

    public function __construct(
        public readonly string $slug,
        public readonly string $from,
        public readonly string $to
    ) {
    }

    public function add(string $action, string $path, string $note = ''): self
    {
        $this->entries[$path] = [
            'action' => $action,
            'path' => $path,
            'note' => $note,
        ];

        return $this;
    }

    /** A reason the update cannot proceed at all, e.g. an unmet requirement. */
    public function problem(string $message): self
    {
        $this->problems[] = $message;

        return $this;
    }

    /** @return array<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string> paths with this action, sorted */
    public function paths(string $action): array
    {
        $paths = array_keys(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['action'] === $action
        ));

        sort($paths);

        return $paths;
    }

    public function count(string $action): int
    {
        return count($this->paths($action));
    }

    /** @return array<string, array{action:string, path:string, note:string}> */
    public function all(): array
    {
        ksort($this->entries);

        return $this->entries;
    }

    /** Paths that would lose a local edit. */
    public function conflicts(): array
    {
        return $this->paths(self::CONFLICT);
    }

    /** Paths a package wanted to write but is not allowed to. */
    public function blocked(): array
    {
        return $this->paths(self::BLOCKED);
    }

    /** Paths where a release would land on a file the operator owns. */
    public function collisions(): array
    {
        return $this->paths(self::COLLISION);
    }

    /**
     * Whether this plan may be applied.
     *
     * A conflict, a collision or a blocked path is fatal by default: those
     * are the cases where proceeding would discard the operator's work,
     * overwrite a file that was never the core's, or write somewhere a
     * package has no business writing.
     */
    public function isSafe(): bool
    {
        return $this->problems === []
            && $this->conflicts() === []
            && $this->collisions() === []
            && $this->blocked() === [];
    }

    /** Whether anything would actually change. */
    public function isEmpty(): bool
    {
        return $this->count(self::ADD) === 0
            && $this->count(self::REPLACE) === 0
            && $this->count(self::DELETE) === 0;
    }

    /** e.g. "3 new, 12 replaced, 40 unchanged, 1 conflict" */
    public function summary(): string
    {
        $parts = [];

        // Written out per action rather than pluralised by rule: "deleted"
        // and "unchanged" are already past participles and adding an -s to
        // them reads as a typo.
        foreach ([
            self::ADD => ['%d new', '%d new'],
            self::REPLACE => ['%d replaced', '%d replaced'],
            self::DELETE => ['%d deleted', '%d deleted'],
            self::IDENTICAL => ['%d unchanged', '%d unchanged'],
            self::CONFLICT => ['%d conflict', '%d conflicts'],
            self::COLLISION => ['%d collision', '%d collisions'],
            self::BLOCKED => ['%d blocked', '%d blocked'],
        ] as $action => [$one, $many]) {
            $count = $this->count($action);

            if ($count > 0) {
                $parts[] = sprintf($count === 1 ? $one : $many, $count);
            }
        }

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }

    /**
     * The plan as printable lines.
     *
     * Grouped by action and truncated, because a core release touches
     * upwards of a hundred files and an untruncated list scrolls the
     * conflicts -- the only part that needs a decision -- off the screen.
     * Conflicts and blocked paths are never truncated for that reason.
     *
     * @param  bool $verbose include unchanged files and list everything
     * @return array<string>
     */
    public function lines(bool $verbose = false): array
    {
        $marks = [
            self::CONFLICT => '!',
            self::COLLISION => '!',
            self::BLOCKED => 'x',
            self::ADD => '+',
            self::REPLACE => '~',
            self::DELETE => '-',
            self::IDENTICAL => '=',
        ];

        // How many of each to show. Anything needing attention is shown in
        // full; routine changes are counted.
        $limits = [
            self::CONFLICT => PHP_INT_MAX,
            self::COLLISION => PHP_INT_MAX,
            self::BLOCKED => PHP_INT_MAX,
            self::ADD => 10,
            self::REPLACE => 10,
            self::DELETE => 10,
            self::IDENTICAL => 0,
        ];

        $lines = [];

        foreach ($marks as $action => $mark) {
            if ($action === self::IDENTICAL && !$verbose) {
                continue;
            }

            $paths = $this->paths($action);

            if ($paths === []) {
                continue;
            }

            $limit = $verbose ? PHP_INT_MAX : $limits[$action];
            $shown = $limit === PHP_INT_MAX ? $paths : array_slice($paths, 0, $limit);

            foreach ($shown as $path) {
                $note = $this->entries[$path]['note'] ?? '';

                $lines[] = sprintf(
                    '  %s %s%s',
                    $mark,
                    $path,
                    $note === '' ? '' : '   (' . $note . ')'
                );
            }

            $hidden = count($paths) - count($shown);

            if ($hidden > 0) {
                $lines[] = sprintf('  %s ... and %d more', $mark, $hidden);
            }
        }

        return $lines;
    }

    public function describe(): string
    {
        return sprintf(
            '%s %s -> %s: %s',
            $this->slug,
            $this->from === '' ? 'not installed' : $this->from,
            $this->to,
            $this->summary()
        );
    }
}
