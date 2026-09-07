<?php

/**
 * The hub's own copy of the archive code, and the update plan.
 *
 * The hub ships a copy of Botex\Archive\* so it can be deployed to a server
 * that has no bot on it. That copy is what makes the format work at both
 * ends, and a silent divergence between the two is the worst kind of bug
 * here: packages would be built to one set of rules and read by another.
 */

use Botex\Archive\Hash;
use Botex\Update\Plan;

group('Hub');

check('the hub carries the archive code it needs to stand alone', static function () {
    $required = ['Zip', 'Writer', 'Reader', 'Manifest', 'Package', 'Hash', 'Version', 'ArchiveException'];

    foreach ($required as $class) {
        if (!is_file(__DIR__ . '/../../hub/src/Archive/' . $class . '.php')) {
            return "hub/src/Archive/{$class}.php is missing";
        }
    }

    return true;
});

check('the hub copy of the archive code is identical to the bot\'s', static function () {
    $classes = ['Zip', 'Writer', 'Reader', 'Manifest', 'Package', 'Hash', 'Version', 'ArchiveException'];
    $drift = [];

    foreach ($classes as $class) {
        $bot = __DIR__ . '/../../src/Archive/' . $class . '.php';
        $hub = __DIR__ . '/../../hub/src/Archive/' . $class . '.php';

        if (!is_file($bot) || !is_file($hub)) {
            continue;
        }

        if (!Hash::matches(Hash::file($bot), Hash::file($hub))) {
            $drift[] = $class;
        }
    }

    return $drift === []
        ? true
        : 'these have drifted from src/Archive/: ' . implode(', ', $drift)
            . ' -- the hub would build packages the bot reads by different rules';
});

check('the hub needs no composer to run', static function () {
    // The point of the hub being dependency-free: it installs by copying a
    // folder onto a server. A require of vendor/ would break that.
    $files = glob(__DIR__ . '/../../hub/src/*.php') ?: [];
    $files[] = __DIR__ . '/../../hub/public/index.php';
    $files[] = __DIR__ . '/../../hub/bin/hub';

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (str_contains($contents, 'vendor/autoload')) {
            return basename($file) . ' requires composer\'s autoloader';
        }
    }

    return true;
});

group('Update plan');

check('a plan with no problems is safe', static function () {
    $plan = new Plan('core', '1.0.0', '1.0.1');

    $plan->add(Plan::REPLACE, 'src/A.php');
    $plan->add(Plan::ADD, 'src/B.php');
    $plan->add(Plan::IDENTICAL, 'src/C.php');
    $plan->add(Plan::DELETE, 'src/D.php');

    return $plan->isSafe() ? true : 'an ordinary plan was judged unsafe';
});

check('a conflict makes a plan unsafe', static function () {
    $plan = new Plan('core', '1.0.0', '1.0.1');

    $plan->add(Plan::REPLACE, 'src/A.php');
    $plan->add(Plan::CONFLICT, 'src/B.php', 'you edited this file');

    return !$plan->isSafe() && $plan->conflicts() === ['src/B.php']
        ? true
        : 'a conflicting plan did not report itself unsafe';
});

check('a blocked path makes a plan unsafe', static function () {
    $plan = new Plan('core', '1.0.0', '1.0.1');

    $plan->add(Plan::BLOCKED, 'config/config.php', 'outside the core surface');

    return !$plan->isSafe()
        ? true
        : 'a plan writing outside the core surface was judged safe';
});

check('a stated problem makes a plan unsafe', static function () {
    $plan = new Plan('core', '1.0.0', '1.0.1');

    $plan->add(Plan::REPLACE, 'src/A.php');
    $plan->problem('this package looks incomplete');

    return !$plan->isSafe() && $plan->problems() !== []
        ? true
        : 'a plan with a stated problem was judged safe';
});

check('counts are reported per action', static function () {
    $plan = new Plan('core', '1.0.0', '1.0.1');

    $plan->add(Plan::REPLACE, 'src/A.php');
    $plan->add(Plan::REPLACE, 'src/B.php');
    $plan->add(Plan::IDENTICAL, 'src/C.php');

    return $plan->count(Plan::REPLACE) === 2
        && $plan->count(Plan::IDENTICAL) === 1
        && $plan->count(Plan::DELETE) === 0
        ? true
        : 'the plan miscounted its own actions';
});

check('a large plan does not print a wall of lines', static function () {
    // The reason lines() groups and caps: a core release touches every file,
    // and an unbounded list scrolls the conflicts -- the part that needs
    // reading -- off the screen.
    $plan = new Plan('core', '1.0.0', '1.0.1');

    for ($i = 0; $i < 200; $i++) {
        $plan->add(Plan::REPLACE, "src/File{$i}.php");
    }

    $plan->add(Plan::CONFLICT, 'src/Mine.php', 'you edited this file');

    $lines = $plan->lines();

    if (count($lines) > 40) {
        return 'lines() produced ' . count($lines) . ' lines for a 201-file plan';
    }

    // The conflict must survive the capping, since it is the actionable part.
    foreach ($lines as $line) {
        if (str_contains($line, 'src/Mine.php')) {
            return true;
        }
    }

    return 'the conflict was capped out of the output';
});
