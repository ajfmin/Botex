<?php

/**
 * Semver comparison and constraint checking.
 *
 * Worth checking directly because a wrong answer here is quiet: it does not
 * throw, it just installs the wrong release or reports "up to date" when it
 * is not.
 */

use Botex\Archive\Version;

group('Version');

check('valid versions are accepted', static function () {
    foreach (['1.0.0', '0.0.1', '10.20.30', '1.0.0-beta', '1.0.0-rc.1', '2.3.4+build.5'] as $version) {
        if (!Version::isValid($version)) {
            return "'{$version}' was rejected but is valid";
        }
    }

    return true;
});

check('invalid versions are rejected', static function () {
    foreach (['', '1', '1.0', 'v1.0.0', 'latest', '1.0.0.0', 'a.b.c', '-1.0.0'] as $version) {
        if (Version::isValid($version)) {
            return "'{$version}' was accepted but is not a valid version";
        }
    }

    return true;
});

check('comparison orders releases correctly', static function () {
    $cases = [
        ['1.0.1', '1.0.0', 1],
        ['1.0.0', '1.0.1', -1],
        ['1.0.0', '1.0.0', 0],
        ['1.1.0', '1.0.9', 1],
        ['2.0.0', '1.99.99', 1],
        // Numeric, not lexicographic: the classic bug is 10 sorting before 9.
        ['1.10.0', '1.9.0', 1],
        ['1.0.10', '1.0.9', 1],
    ];

    foreach ($cases as [$left, $right, $expected]) {
        $actual = Version::compare($left, $right);

        if ($actual !== $expected) {
            return "compare({$left}, {$right}) gave {$actual}, expected {$expected}";
        }
    }

    return true;
});

check('a prerelease sorts below its own release', static function () {
    return Version::compare('1.0.0-beta', '1.0.0') === -1
        && Version::compare('1.0.0', '1.0.0-beta') === 1
        ? true
        : 'a prerelease did not sort below the final release';
});

check('build metadata does not affect ordering', static function () {
    return Version::compare('1.0.0+a', '1.0.0+b') === 0
        ? true
        : 'build metadata changed the comparison';
});

check('greater-than is strict', static function () {
    return Version::greaterThan('1.0.1', '1.0.0')
        && !Version::greaterThan('1.0.0', '1.0.0')
        && !Version::greaterThan('0.9.0', '1.0.0')
        ? true
        : 'greaterThan is not a strict comparison';
});

check('equals ignores build metadata', static function () {
    return Version::equals('1.0.0', '1.0.0')
        && Version::equals('1.0.0+a', '1.0.0+b')
        && !Version::equals('1.0.0', '1.0.1')
        ? true
        : 'equals does not treat build metadata as insignificant';
});

check('prereleases are excluded from newest() by default', static function () {
    $versions = ['1.0.0', '1.1.0-beta'];

    return Version::newest($versions) === '1.0.0'
        && Version::newest($versions, prereleases: true) === '1.1.0-beta'
        ? true
        : 'newest() gave ' . var_export(Version::newest($versions), true);
});

check('caret constraints allow only compatible releases', static function () {
    $cases = [
        ['^1.2.0', '1.2.0', true],
        ['^1.2.0', '1.9.9', true],
        ['^1.2.0', '1.1.0', false],
        ['^1.2.0', '2.0.0', false],
        ['>=1.2.0', '2.0.0', true],
        ['>=1.2.0', '1.1.9', false],
        ['1.2.3', '1.2.3', true],
        ['1.2.3', '1.2.4', false],
    ];

    foreach ($cases as [$constraint, $version, $expected]) {
        $actual = Version::satisfies($version, $constraint);

        if ($actual !== $expected) {
            return sprintf(
                'satisfies(%s, %s) gave %s, expected %s',
                $version,
                $constraint,
                var_export($actual, true),
                var_export($expected, true)
            );
        }
    }

    return true;
});

check('a partial constraint operand is accepted', static function () {
    // `>=8.2` is how composer.json spells a PHP requirement, so a core
    // release built from source carries it verbatim. Holding the operand to
    // major.minor.patch made every such release unsatisfiable and reported
    // "needs PHP >=8.2, this is 8.3.28" -- refusing an update that would
    // have installed fine.
    $cases = [
        ['>=8.2', '8.3.28', true],
        ['>=8.2', '8.1.0', false],
        ['>=8', '8.0.0', true],
        ['<8.4', '8.3.28', true],
        ['<8.4', '8.4.0', false],
        ['>=1.2,<2', '1.5.0', true],
        ['>=1.2,<2', '2.0.0', false],
        ['8.2', '8.2.0', true],
        ['8.2', '8.2.1', false],
        // Still refused, since neither side parses as a version at all.
        ['>=8.2', 'not-a-version', false],
        ['>=not-a-version', '8.3.0', false],
    ];

    foreach ($cases as [$constraint, $version, $expected]) {
        $actual = Version::satisfies($version, $constraint);

        if ($actual !== $expected) {
            return sprintf(
                'satisfies(%s, %s) gave %s, expected %s',
                $version,
                $constraint,
                var_export($actual, true),
                var_export($expected, true)
            );
        }
    }

    return true;
});

check('a partial version is still not publishable', static function () {
    // The looser constraint parsing must not leak into what may be
    // published: '1.2' as a release version would become a store path and a
    // package filename that never compares equal to the '1.2.0' someone
    // meant.
    foreach (['1', '1.2', 'v1.2.3'] as $version) {
        if (Version::isValid($version)) {
            return "isValid('{$version}') should be false";
        }
    }

    return Version::isValid('1.2.3') ? true : "isValid('1.2.3') should be true";
});

check('the newest of a list is picked', static function () {
    $versions = ['1.0.0', '1.10.0', '1.2.0', '0.9.9', '1.9.0'];

    return Version::newest($versions) === '1.10.0'
        ? true
        : 'newest() picked ' . var_export(Version::newest($versions), true);
});
