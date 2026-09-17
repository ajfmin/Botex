<?php

/**
 * Broadcasting, checked where it fails quietly.
 *
 * Three things here are wrong in ways nothing ever errors about, which
 * is exactly why they are checked offline rather than only in the
 * application:
 *
 *   - **The rate.** A broadcast that sends too fast still "works": every
 *     message is accepted until Telegram starts refusing, and the flood
 *     wait that follows applies to every other message the bot sends. A
 *     wrong number here is only visible as customers not getting
 *     answers, hours later.
 *   - **The classification.** Counting a blocked recipient as a failure,
 *     or a bad parse_mode as an audience that deleted their accounts,
 *     produces a report that is confidently wrong -- and a report is the
 *     entire point of sending one.
 *   - **The cursor arithmetic.** Progress that reads over 100%, or a
 *     percentage that goes backwards, is a bug nobody can act on.
 *
 * Nothing here touches a database, a network or a bot token.
 */

use Botex\Bot\Admin\BroadcastAction;
use Botex\Broadcast\Outcome;
use Botex\Broadcast\Sender;
use Botex\Broadcast\Status;
use Botex\Model\Broadcast;
use Botex\Support\Config;

group('Broadcast outcomes');

check('a delivered message is counted as delivered', static function () {
    return Outcome::of(['ok' => true, 'result' => ['message_id' => 9]]) === Outcome::SENT
        ? true
        : 'an ok response was not read as sent';
});

check('403 is somebody blocking the bot, not a failure', static function () {
    $blocked = Outcome::of([
        'ok' => false,
        'error_code' => 403,
        'description' => 'Forbidden: bot was blocked by the user',
    ]);

    if ($blocked !== Outcome::BLOCKED) {
        return 'a block was classified as ' . $blocked->value;
    }

    // The distinction that makes the report worth reading: this is a
    // person's decision, and lumping it in with failures turns a healthy
    // bot into an alarming one.
    return $blocked->isUnreachable() ? true : 'a blocked chat was not marked unreachable';
});

check('a deleted account is told apart from a blocked one', static function () {
    $gone = Outcome::of([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: chat not found',
    ]);

    if ($gone !== Outcome::GONE) {
        return 'a missing chat was classified as ' . $gone->value;
    }

    return $gone->isUnreachable() ? true : 'a missing chat was not marked unreachable';
});

check('a bad request is not mistaken for a missing audience', static function () {
    // The case that would otherwise read as everybody deleting their
    // account at once: one malformed message, four hundred 400s.
    $failed = Outcome::of([
        'ok' => false,
        'error_code' => 400,
        'description' => "Bad Request: can't parse entities: unsupported start tag",
    ]);

    if ($failed !== Outcome::FAILED) {
        return 'a malformed message was classified as ' . $failed->value;
    }

    return $failed->isUnreachable() ? 'a malformed message marked the recipient unreachable' : true;
});

check('a request that never got an answer is a failure, not a block', static function () {
    $none = Outcome::of([
        'ok' => false,
        'error_code' => \Botex\Telegram\Support\Request::NO_RESPONSE,
        'description' => 'curl: connection timed out',
    ]);

    return $none === Outcome::FAILED ? true : 'a transport failure was classified as ' . $none->value;
});

check('429 asks for a retry, and the wait it asks for is capped', static function () {
    $response = ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 7]];

    if (Outcome::of($response) !== Outcome::THROTTLED) {
        return 'a flood wait was not classified as throttled';
    }

    if (Outcome::retryAfter($response) !== 7) {
        return 'the requested wait was not read back';
    }

    // Comes off the network, so a nonsense value must not become an hour
    // of a worker sitting still.
    return Outcome::retryAfter(['parameters' => ['retry_after' => 99999]]) === 60
        ? true
        : 'an absurd retry_after was obeyed rather than capped';
});

group('Broadcast rate');

$sender = static function (array $config) use ($temp): Sender {
    // Only rate() and sliceSeconds() are exercised, and neither touches
    // a collaborator -- so the rest is passed as null-safe stand-ins
    // rather than a bot token this test has no business having.
    return new Sender(
        new \Botex\Telegram\Bot(''),
        new \Botex\Repository\UserRepository(),
        new \Botex\Repository\BroadcastRepository(),
        new Config($config),
        new \Botex\Support\Log\Logger($temp . '/logs')
    );
};

check('the default rate is half what Telegram allows', static function () use ($sender) {
    $rate = $sender([])->rate();

    if ($rate !== Sender::DEFAULT_RATE) {
        return "the default rate is {$rate}, not " . Sender::DEFAULT_RATE;
    }

    // The number that matters: the documented ceiling is about 30/s and
    // exceeding it penalises every other message the bot sends, so the
    // default has to leave room rather than sit on the line.
    return $rate <= 15 ? true : 'the default rate leaves no headroom under the API limit';
});

check('a configured rate is never allowed past the API limit', static function () use ($sender) {
    $rate = $sender(['broadcast' => ['rate' => 500]])->rate();

    return $rate === 30 ? true : "a rate of 500 was clamped to {$rate}, not 30";
});

check('a nonsensical rate cannot stop a broadcast dead', static function () use ($sender) {
    // Zero would be a division by zero in the pacing, and a negative one
    // would be a send that never comes due.
    $rate = $sender(['broadcast' => ['rate' => 0]])->rate();

    return $rate >= 1 ? true : "a rate of 0 was kept as {$rate}";
});

check('a slice is bounded at both ends', static function () use ($sender) {
    $long = $sender(['broadcast' => ['slice' => 100000]])->sliceSeconds();
    $short = $sender(['broadcast' => ['slice' => 0]])->sliceSeconds();

    if ($long > 300) {
        // A slice longer than the default job lease looks like an
        // abandoned job to the next worker.
        return "a slice of {$long}s would outlast any sane lease";
    }

    return $short >= 5 ? true : "a slice of {$short}s would be all overhead";
});

check('the estimate is the audience divided by the rate', static function () use ($sender) {
    $instance = $sender(['broadcast' => ['rate' => 10]]);

    if ($instance->estimate(1000) !== 100) {
        return 'a thousand at ten a second was not estimated as 100 seconds';
    }

    return $instance->estimate(0) === 0 ? true : 'an empty audience was given a duration';
});

group('Broadcast progress and control');

$row = static function (array $attributes): Broadcast {
    $broadcast = new Broadcast();
    $broadcast->forceFill($attributes);

    return $broadcast;
};

check('progress counts every outcome, not just the good one', static function () use ($row) {
    $broadcast = $row([
        'total' => 1000,
        'sent' => 700,
        'blocked' => 200,
        'gone' => 50,
        'failed' => 50,
    ]);

    if ($broadcast->attempted() !== 1000) {
        return 'attempted was ' . $broadcast->attempted() . ', not 1000';
    }

    if ($broadcast->percent() !== 100) {
        return 'a fully tried audience read as ' . $broadcast->percent() . '%';
    }

    return $broadcast->remaining() === 0 ? true : 'recipients were left over at 100%';
});

check('progress cannot read over 100% when the bot gains users', static function () use ($row) {
    // total is measured once, when the send begins; people who press
    // /start during it are still sent to, which pushes attempted past it.
    $broadcast = $row(['total' => 100, 'sent' => 130]);

    return $broadcast->percent() === 100
        ? true
        : 'progress read ' . $broadcast->percent() . '% instead of being clamped';
});

check('an audience of nobody does not divide by zero', static function () use ($row) {
    return $row(['total' => 0, 'sent' => 0])->percent() === 0 ? true : 'an empty send reported progress';
});

check('only a paused broadcast can be resumed', static function () {
    foreach ([Status::QUEUED, Status::SENDING, Status::DONE, Status::CANCELLED] as $status) {
        if ($status->isResumable()) {
            return $status->value . ' offered a Resume button';
        }
    }

    if (!Status::PAUSED->isResumable()) {
        return 'a paused broadcast could not be resumed, which strands it';
    }

    // The worker picks up exactly these two and nothing else.
    return Status::QUEUED->isActive() && Status::SENDING->isActive()
        && !Status::PAUSED->isActive() && !Status::CANCELLED->isActive()
        ? true
        : 'the worker would pick up a broadcast that was stopped';
});

check('a control button carries the broadcast it was drawn for', static function () {
    $data = BroadcastAction::to(BroadcastAction::PAUSE, 42);
    $parsed = BroadcastAction::parse($data);

    if ($parsed !== [BroadcastAction::PAUSE, 42]) {
        return 'pause:42 did not survive the round trip';
    }

    // Without the id, a button sitting in yesterday's message would
    // pause whatever happens to be running today.
    $new = BroadcastAction::parse(BroadcastAction::to(BroadcastAction::NEW));

    if ($new !== [BroadcastAction::NEW, 0]) {
        return 'the compose button did not parse';
    }

    return strlen($data) <= 64 ? true : 'the callback data is over the 64-byte Telegram limit';
});

check('callback data that was not drawn by us is refused', static function () {
    foreach ([
        'admin:bc:destroy:1',
        'admin:bc:pause:; DROP TABLE users',
        'admin:bc:pause:-1',
        'admin:topup:m:card',
        'nonsense',
    ] as $data) {
        if (BroadcastAction::parse($data) !== null) {
            return "accepted '{$data}'";
        }
    }

    return true;
});
