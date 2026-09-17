<?php

/**
 * What an `update:all` run reports.
 *
 * The distinction checked here decides an exit code, which is the one
 * thing a deploy script reads. A batch that updated everything it could
 * and left one extension waiting on the core release in the same run has
 * not failed -- re-running it finishes the job -- while a batch that
 * refused an extension outright has. Collapsing the two either makes a
 * green deploy red, or hides a refusal behind a "run it again" nobody
 * reads.
 *
 * Applying anything needs an archive and a disk, so that is not tested
 * here; how the result is reported does not, and it is the part that is
 * silently wrong when it is wrong.
 */

use Botex\Update\Plan;
use Botex\Update\Step;

group('Batch updates');

check('a deferred target is not reported as a failure', static function () {
    $deferred = Step::deferred('Clock', 'needs Botex ^1.4, which this run installs');

    if ($deferred->state !== Step::DEFERRED) {
        return 'a deferred step reported state ' . $deferred->state;
    }

    // The console counts anything not failed and not deferred as applied,
    // and exits non-zero only on a failure.
    if ($deferred->succeeded()) {
        return 'a deferred step counted as applied, so nobody would re-run it';
    }

    $failed = Step::failed('Clock', 'needs PHP 8.4, this is 8.3');

    return $failed->state === Step::FAILED && !$failed->succeeded()
        ? true
        : 'a refused step was not reported as a failure';
});

check('a dry run is reported as applying nothing', static function () {
    $planned = Step::planned('core', new Plan('core', '1.3.1', '1.4.0'));

    if ($planned->state !== Step::PLANNED) {
        return 'a planned step reported state ' . $planned->state;
    }

    // Counted as a success so --dry-run exits zero on a healthy install,
    // which is what makes it usable as a check in CI.
    return $planned->succeeded() ? true : 'a clean dry run would have exited non-zero';
});

check('the core is recognisable in a batch, whatever else ran', static function () {
    if (!Step::failed('core', 'nope')->isCore()) {
        return 'the core step was not recognised, so no restart would be advised';
    }

    return Step::failed('Clock', 'nope')->isCore()
        ? 'an extension was mistaken for the core'
        : true;
});

check('every step prints one line, and says which target it is about', static function () {
    $steps = [
        Step::deferred('Clock', 'waiting on the core'),
        Step::failed('Vpn', 'needs PHP 8.4'),
        Step::planned('core', new Plan('core', '1.3.1', '1.4.0')),
    ];

    foreach ($steps as $step) {
        $line = $step->line();

        if (str_contains($line, "\n") || trim($line) === '') {
            return 'a step printed something other than one line';
        }

        if (!str_contains($line, $step->target)) {
            return "a {$step->state} line does not name its target";
        }
    }

    return true;
});
