<?php

/**
 * The worker service, and the one thing about it that must never be wrong.
 *
 * A Botex install may have exactly one worker. Two of them run every
 * schedule twice: two reminder messages to the same customer, two syncs
 * fighting over the same panel, two prune passes. Nothing errors when it
 * happens -- the bot looks busy and healthy -- which is why the guarantee
 * is checked here rather than trusted.
 *
 * It rests on one property: **the unit name is a pure function of the
 * install path**. Same install, same name, from any shell and any user,
 * so installing twice replaces one unit instead of accumulating two.
 * Different installs, different names, so two bots on one box do not
 * fight over a single unit.
 *
 * Everything below is decided from a path and a config array. Nothing
 * calls systemctl, writes a unit or needs a database, so it runs on this
 * Windows dev box and on a host where the bot is not installed yet.
 */

use Botex\Bot\Job\SystemdService;
use Botex\Support\Config;
use Botex\Support\Log\Logger;

group('Worker service');

$service = static function (string $root) use ($temp): SystemdService {
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new \RuntimeException('could not create ' . $root);
    }

    return new SystemdService(
        new Config(['paths' => ['root' => $root, 'storage' => $root . '/storage']]),
        new Logger($temp . '/logs')
    );
};

check('one install always gets the same unit name', static function () use ($service, $temp) {
    $root = $temp . '/svc-stable';

    $first = $service($root)->unitName();
    $second = $service($root)->unitName();

    if ($first !== $second) {
        return "the same install produced {$first} and then {$second}";
    }

    // A trailing slash is the same directory, and a second unit for it
    // would be a second worker.
    return $service($root . '/')->unitName() === $first
        ? true
        : 'a trailing slash produced a different unit name';
});

check('two installs never share a unit', static function () use ($service, $temp) {
    $one = $service($temp . '/svc-a')->unitName();
    $two = $service($temp . '/svc-b')->unitName();

    return $one === $two
        ? "both installs would be supervised by {$one}"
        : true;
});

check('installs with the same folder name still differ', static function () use ($service, $temp) {
    // The realistic collision: /srv/bot1/botex and /srv/bot2/botex. The
    // readable half of the name is identical and only the hash separates
    // them, which is exactly what the hash is for.
    $one = $service($temp . '/one/botex')->unitName();
    $two = $service($temp . '/two/botex')->unitName();

    if ($one === $two) {
        return 'two installs called botex would share ' . $one;
    }

    return str_starts_with($one, SystemdService::PREFIX) && str_starts_with($two, SystemdService::PREFIX)
        ? true
        : 'a unit name did not carry the shared prefix strays are found by';
});

check('the unit name is a legal systemd name', static function () use ($service, $temp) {
    $name = $service($temp . '/Some Bot (v2)!')->name();

    // systemd unit names allow letters, digits and a small set of
    // punctuation; a space or a bracket out of a directory name would
    // produce a unit that cannot be enabled.
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
        return "'{$name}' is not a usable unit name";
    }

    return strlen($name . '.service') <= 64
        ? true
        : 'the unit name is too long: ' . $name;
});

check('the unit runs this bot, under supervision, as one process', static function () use ($service, $temp) {
    $root = $temp . '/svc-unit';
    $unit = $service($root)->unitContents();

    foreach ([
        'Type=simple' => 'the unit does not declare a single foreground process',
        'jobs:work' => 'the unit does not start the worker',
        'Restart=always' => 'a worker that exits would stay stopped',
        'KillSignal=SIGTERM' => 'the worker would not be asked to stop cleanly',
    ] as $needle => $problem) {
        if (!str_contains($unit, $needle)) {
            return $problem;
        }
    }

    // The worker finishes the job in hand on SIGTERM, so systemd has to
    // wait for it rather than killing it mid-write.
    if (!str_contains($unit, 'TimeoutStopSec=' . SystemdService::STOP_TIMEOUT)) {
        return 'the unit does not give the worker time to finish a job';
    }

    // ExecStart must be absolute on both halves: systemd runs it with no
    // PATH and no shell.
    if (preg_match('/^ExecStart=(\S+) (\S+) jobs:work$/m', $unit, $matches) !== 1) {
        return 'ExecStart is not one interpreter and one absolute script';
    }

    // Compared with separators normalised: the unit always uses forward
    // slashes, and on this dev box sys_get_temp_dir() does not.
    $wanted = str_replace('\\', '/', $root);

    return str_contains($matches[2], $wanted)
        ? true
        : 'ExecStart points at ' . $matches[2] . ', not ' . $wanted;
});

check('the unit says which install it belongs to', static function () use ($service, $temp) {
    // WorkingDirectory is what strays() reads to find another unit
    // pointing at this same install, so it has to be there and has to be
    // the resolved path.
    $root = $temp . '/svc-where';
    $unit = $service($root)->unitContents();

    if (preg_match('/^WorkingDirectory=(.*)$/m', $unit, $matches) !== 1) {
        return 'the unit has no WorkingDirectory, so a stray could never be found';
    }

    return str_contains(trim($matches[1]), 'svc-where')
        ? true
        : 'WorkingDirectory does not name this install';
});

check('a host without systemd is told so rather than half-installed', static function () use ($service, $temp) {
    $instance = $service($temp . '/svc-os');
    $reason = $instance->unsupported();

    if (PHP_OS_FAMILY !== 'Linux') {
        if ($reason === null) {
            return 'a non-Linux host reported itself as able to run a systemd service';
        }

        // Every mutating call has to refuse before touching anything.
        foreach (['install', 'start', 'stop', 'restart', 'remove'] as $action) {
            try {
                $instance->$action();

                return "{$action}() went ahead on a host with no systemd";
            } catch (\Botex\Bot\Job\ServiceException) {
                // what should happen
            }
        }

        // Reading is still allowed, because printing the unit is how an
        // operator on an unsupported host installs it themselves.
        return $instance->unitContents() !== '' && $instance->status()['supported'] === false
            ? true
            : 'an unsupported host could not even print its unit';
    }

    return true;
});

check('a unit left behind by a move is recognised as ours', static function () {
    // The case a derived name cannot cover by itself: rename or move the
    // install and the name changes with it, leaving the old unit enabled
    // and pointing at a path that is gone. It is found by what it says it
    // supervises, not by what it is called.
    $unit = "[Service]\nWorkingDirectory=/srv/shop/botex\n"
        . "ExecStart=/usr/bin/php /srv/shop/botex/bin/console jobs:work\n";

    foreach ([
        ['/srv/shop/botex', true, 'the install it names'],
        ['/srv/shop/botex/', true, 'the same path with a trailing slash'],
        ['/srv/shop/other', false, 'a different install'],
        // The one that would be wrong with a str_contains(): a path the
        // unit's path merely starts with.
        ['/srv/shop/bot', false, 'a prefix of that path'],
    ] as [$root, $expected, $what]) {
        if (SystemdService::ownsRoot($unit, $root) !== $expected) {
            return 'got the wrong answer for ' . $what;
        }
    }

    // Nothing without a WorkingDirectory can be claimed: it might be
    // anybody's, and disabling somebody else's unit is worse than leaving
    // a stray.
    return SystemdService::ownsRoot("[Service]\nExecStart=/usr/bin/php x\n", '/srv/shop/botex')
        ? 'a unit with no WorkingDirectory was claimed'
        : true;
});

check('nothing is written by asking about it', static function () use ($service, $temp) {
    $root = $temp . '/svc-readonly';
    $instance = $service($root);

    $instance->status();
    $instance->unitName();
    $instance->unitContents();
    $instance->strays();

    return is_file($instance->unitPath())
        ? 'reading the status wrote a unit file'
        : true;
});
