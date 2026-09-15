<?php

/**
 * The top-up switch, and the panel's one-control-one-place rule.
 *
 * Both are checked here rather than only in the application because both
 * fail quietly. A switch that defaults the wrong way opens a payment
 * route nobody agreed to; a panel that renders the same control twice
 * still works, it is just confusing, so nothing ever errors to say so.
 *
 * Neither needs a database, which is what lets them run on a fresh host.
 */

use Botex\Bot\Admin\HasSubMenu;
use Botex\Bot\Admin\Panel;
use Botex\Bot\Admin\Sections;
use Botex\Bot\TopUp\MethodState;
use Botex\Bot\TopUp\PaymentMethodInterface;
use Botex\Bot\TopUp\TopUpService;

group('Top-up method state');

$stateFile = static function (): string {
    return sys_get_temp_dir() . '/botex-selftest-topup-' . bin2hex(random_bytes(6)) . '.json';
};

check('a method nobody has decided on is off', static function () use ($stateFile) {
    $file = $stateFile();
    $state = new MethodState($file);

    try {
        // The opposite of Extension\State, deliberately: an extension
        // appearing and working is a convenience, a payment route
        // appearing and taking money is not.
        if ($state->isEnabled('anything')) {
            return 'an unknown method reported itself as enabled';
        }

        return $state->isKnown('anything') ? 'an unknown method reported itself as known' : true;
    } finally {
        @unlink($file);
    }
});

check('switching on and off survives a reload', static function () use ($stateFile) {
    $file = $stateFile();

    try {
        (new MethodState($file))->enable('gateway');

        if (!(new MethodState($file))->isEnabled('gateway')) {
            return 'enabled state did not persist';
        }

        (new MethodState($file))->disable('gateway');

        if ((new MethodState($file))->isEnabled('gateway')) {
            return 'disabled state did not persist';
        }

        // Still known: "off" is a decision, and forgetting it would let
        // the method default back on the next time it is looked at.
        return (new MethodState($file))->isKnown('gateway') ? true : 'a disabled method was forgotten';
    } finally {
        @unlink($file);
    }
});

check('toggle reports the state it landed in', static function () use ($stateFile) {
    $file = $stateFile();
    $state = new MethodState($file);

    try {
        if ($state->toggle('m') !== true) {
            return 'first toggle did not report on';
        }

        if ($state->toggle('m') !== false) {
            return 'second toggle did not report off';
        }

        $state->forget('m');

        return $state->isKnown('m') ? 'forget() left the method behind' : true;
    } finally {
        @unlink($file);
    }
});

check('a missing or corrupt state file is treated as nothing switched on', static function () use ($stateFile) {
    $file = $stateFile();
    file_put_contents($file, 'not json at all');

    try {
        $state = new MethodState($file);

        // Failing closed matters more here than anywhere else in the
        // project: the alternative reading of an unreadable file is
        // "everything is on".
        return $state->isEnabled('m') || $state->all() !== [] ? 'a corrupt file enabled something' : true;
    } finally {
        @unlink($file);
    }
});

check('the ledger reference type is stable', static function () {
    // Written into every top-up wallet entry. Changing it orphans the
    // link between an entry and the method that produced it.
    return TopUpService::REFERENCE === 'topup'
        ? true
        : 'reference type is now ' . TopUpService::REFERENCE;
});

check('a payment method can explain its own condition', static function () {
    // description() has to be an instance method, not a static one. A
    // method that is switched on and still not taking money -- a gateway
    // with test credentials, a card method with no card number set --
    // says so on this line, and a static method cannot see a setting.
    // Made static again and the panel would silently go back to printing
    // a fixed sentence at an admin who is trying to find out what is
    // wrong.
    $description = new ReflectionMethod(PaymentMethodInterface::class, 'description');

    if ($description->isStatic()) {
        return 'PaymentMethodInterface::description() is static again';
    }

    // Read rather than reflected: composer autoloads `src/`, not
    // `extensions/`, so a reflection pass here would find no classes and
    // quietly pass whatever it was given.
    foreach (glob(dirname(__DIR__, 2) . '/extensions/*/*/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (!preg_match('/class\s+\w+[^{]*implements[^{]*PaymentMethodInterface/', $source)) {
            continue;
        }

        if (preg_match('/static\s+function\s+description\s*\(/', $source)) {
            return basename($file) . ' declares description() static';
        }

        if (!preg_match('/function\s+description\s*\(/', $source)) {
            return basename($file) . ' does not implement description()';
        }
    }

    return true;
});

group('Admin panel buttons');

check('navigation is no longer offered inline as well', static function () {
    // These two rendered the sections, and a Back to them, as inline
    // buttons underneath a keyboard that already carried both. Their
    // absence is the rule: navigation is a keyboard label, and only
    // management is an inline button.
    foreach (['menu', 'backKeyboard'] as $method) {
        if (method_exists(Panel::class, $method)) {
            return "Panel::{$method}() is back; it renders navigation inline";
        }
    }

    return method_exists(Panel::class, 'homeText')
        ? true
        : 'Panel::homeText() is missing, so the home screen has nothing to say';
});

check('no two panel labels collide', static function () {
    // A reply-keyboard tap carries nothing but its text, so two screens
    // sharing a label are the same button. Checked across core's own
    // sections and every screen they contribute.
    $sections = new ReflectionClass(Sections::class);
    $labels = [];

    foreach ($sections->getConstant('CORE') as $class) {
        $labels[] = $class::title();

        if (is_subclass_of($class, HasSubMenu::class)) {
            foreach ($class::menuItems() as $label => $_) {
                $labels[] = (string) $label;
            }
        }
    }

    $duplicates = array_diff_assoc($labels, array_unique($labels));

    return $duplicates === [] ? true : 'duplicate label(s): ' . implode(', ', $duplicates);
});

check('every core section has a usable key', static function () {
    $sections = new ReflectionClass(Sections::class);

    foreach ($sections->getConstant('CORE') as $class) {
        $key = $class::key();

        // A colon would break the admin:s:<key> callback format, which
        // is how every inline route into a section is addressed.
        if ($key === '' || str_contains($key, ':')) {
            return "{$class} has an invalid key: '{$key}'";
        }

        if ($class::title() === '') {
            return "{$class} has no title, so its keyboard label would be blank";
        }
    }

    return true;
});
