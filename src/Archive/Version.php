<?php

namespace Botex\Archive;

/**
 * Semantic version comparison and the constraint subset a package needs.
 *
 * PHP's own version_compare() almost does this, but it treats an unknown
 * suffix as newer than the release it qualifies -- version_compare('1.0.0',
 * '1.0.0-beta') answers that the beta is greater -- which would offer every
 * install a downgrade to a prerelease. So ordering is done here, by the
 * semver rules, and version_compare is used only where it is right.
 *
 * Supported constraints, which is what a manifest's `requires` may hold:
 *
 *   1.2.3     exactly
 *   >=1.2.0   and >, <, <=, =
 *   ^1.2.0    same major, at least this (the usual choice)
 *   ~1.2.0    same major+minor, at least this
 *   *         anything
 *   >=1.2,<2  comma-joined, all must hold
 */
class Version
{
    /**
     * Lenient: major, with optional minor, patch, -prerelease and +build.
     *
     * Partial versions are allowed here because constraints are written that
     * way -- `^1.2` and `>=2` are ordinary things to type -- and parse() is
     * what reads them. A *release* is held to the stricter RELEASE below.
     */
    private const PATTERN = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/';

    /**
     * Strict: exactly major.minor.patch, no `v` prefix.
     *
     * What every published version is checked against. Two reasons for the
     * separate pattern: `1` or `1.2` as a release is almost always a typo
     * for a real version and silently becoming 1.0.0 would publish something
     * nobody named; and the string ends up in a store path and a package
     * filename, where `v1.0.0` and `1.0.0` would be two spellings of one
     * release that never compare equal as strings.
     */
    private const RELEASE = '/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/';

    /** Whether this is a complete, publishable version. */
    public static function isValid(string $version): bool
    {
        return preg_match(self::RELEASE, trim($version)) === 1;
    }

    /**
     * Whether this parses as a version *or* a partial one, as in a
     * constraint. Looser than isValid() on purpose.
     */
    public static function isParseable(string $version): bool
    {
        return preg_match(self::PATTERN, trim($version)) === 1;
    }

    /**
     * Splits a version into [major, minor, patch, prerelease].
     *
     * @return array{0:int,1:int,2:int,3:string}|null
     */
    public static function parse(string $version): ?array
    {
        if (preg_match(self::PATTERN, trim($version), $matches) !== 1) {
            return null;
        }

        return [
            (int) $matches[1],
            (int) ($matches[2] ?? 0),
            (int) ($matches[3] ?? 0),
            // Build metadata is deliberately dropped: semver says it takes
            // no part in ordering.
            $matches[4] ?? '',
        ];
    }

    /**
     * -1, 0 or 1, like the comparison callbacks.
     *
     * @throws ArchiveException on an unparseable version
     */
    public static function compare(string $a, string $b): int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        if ($left === null) {
            throw new ArchiveException("Cannot compare invalid version '{$a}'.");
        }

        if ($right === null) {
            throw new ArchiveException("Cannot compare invalid version '{$b}'.");
        }

        for ($part = 0; $part < 3; $part++) {
            if ($left[$part] !== $right[$part]) {
                return $left[$part] <=> $right[$part];
            }
        }

        return self::comparePrerelease($left[3], $right[3]);
    }

    /**
     * Prerelease ordering: a release outranks any prerelease of itself.
     *
     * 1.0.0-alpha < 1.0.0-alpha.1 < 1.0.0-beta < 1.0.0-rc.1 < 1.0.0
     */
    private static function comparePrerelease(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        // The empty string here means "not a prerelease", which is the
        // highest, not the lowest.
        if ($a === '') {
            return 1;
        }

        if ($b === '') {
            return -1;
        }

        $left = explode('.', $a);
        $right = explode('.', $b);
        $count = max(count($left), count($right));

        for ($index = 0; $index < $count; $index++) {
            // A shorter set of identifiers ranks lower when all the earlier
            // ones are equal: alpha < alpha.1.
            if (!isset($left[$index])) {
                return -1;
            }

            if (!isset($right[$index])) {
                return 1;
            }

            $one = $left[$index];
            $two = $right[$index];

            if ($one === $two) {
                continue;
            }

            $oneNumeric = ctype_digit($one);
            $twoNumeric = ctype_digit($two);

            // Numeric identifiers always rank below alphanumeric ones.
            if ($oneNumeric && $twoNumeric) {
                return (int) $one <=> (int) $two;
            }

            if ($oneNumeric !== $twoNumeric) {
                return $oneNumeric ? -1 : 1;
            }

            return strcmp($one, $two);
        }

        return 0;
    }

    public static function greaterThan(string $a, string $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    /** Whether $version satisfies $constraint. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        // Comma-joined constraints are an AND, e.g. ">=1.2,<2.0".
        foreach (explode(',', $constraint) as $part) {
            if (!self::satisfiesOne($version, trim($part))) {
                return false;
            }
        }

        return true;
    }

    private static function satisfiesOne(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        // Caret: no breaking change, i.e. same major and not older.
        if (str_starts_with($constraint, '^')) {
            $target = substr($constraint, 1);
            $parsed = self::parse($target);
            $current = self::parse($version);

            if ($parsed === null || $current === null) {
                return false;
            }

            return $current[0] === $parsed[0] && self::compare($version, $target) >= 0;
        }

        // Tilde: patch-level changes only.
        if (str_starts_with($constraint, '~')) {
            $target = substr($constraint, 1);
            $parsed = self::parse($target);
            $current = self::parse($version);

            if ($parsed === null || $current === null) {
                return false;
            }

            return $current[0] === $parsed[0]
                && $current[1] === $parsed[1]
                && self::compare($version, $target) >= 0;
        }

        // Longest operators first: ">=" must be tried before ">".
        foreach (['>=', '<=', '!=', '==', '>', '<', '='] as $operator) {
            if (!str_starts_with($constraint, $operator)) {
                continue;
            }

            $target = trim(substr($constraint, strlen($operator)));

            // isParseable, not isValid: an operand inside a constraint is
            // routinely partial. `>=8.2` is how every composer.json in
            // existence spells a PHP requirement, and holding it to
            // major.minor.patch rejected it as unparseable -- which read as
            // "needs PHP >=8.2, this is 8.3.28" and refused an update that
            // was perfectly installable. compare() fills the missing parts
            // with zero, which is what the constraint means.
            if (!self::isParseable($target) || !self::isParseable($version)) {
                return false;
            }

            $result = self::compare($version, $target);

            return match ($operator) {
                '>=' => $result >= 0,
                '<=' => $result <= 0,
                '>' => $result > 0,
                '<' => $result < 0,
                '!=' => $result !== 0,
                default => $result === 0,
            };
        }

        // A bare version means exactly that version. Parseable rather than
        // valid for the same reason as above: `8.2` as a constraint means
        // 8.2.0, and compare() already reads it that way.
        return self::isParseable($constraint) && self::isParseable($version)
            && self::compare($version, $constraint) === 0;
    }

    /**
     * The newest of a list, ignoring prereleases unless asked.
     *
     * @param  array<string> $versions
     */
    public static function newest(array $versions, bool $prereleases = false): ?string
    {
        $best = null;

        foreach ($versions as $version) {
            if (!self::isValid($version)) {
                continue;
            }

            if (!$prereleases && self::isPrerelease($version)) {
                continue;
            }

            if ($best === null || self::greaterThan($version, $best)) {
                $best = $version;
            }
        }

        return $best;
    }

    public static function isPrerelease(string $version): bool
    {
        $parsed = self::parse($version);

        return $parsed !== null && $parsed[3] !== '';
    }

    /**
     * Sorts newest first, dropping anything unparseable.
     *
     * @param  array<string> $versions
     * @return array<string>
     */
    public static function sortDescending(array $versions): array
    {
        $valid = array_values(array_filter($versions, [self::class, 'isValid']));

        usort($valid, static fn (string $a, string $b): int => self::compare($b, $a));

        return $valid;
    }
}
