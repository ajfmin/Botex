<?php

namespace Botex\Bot\TopUp;

use Botex\Bot\Feeder;
use Botex\Extension\Registry;
use Botex\Support\Log\Logger;

/**
 * Allowlist of payment methods, keyed by key().
 *
 * The same shape as [[Runnables]], [[Jobs]] and [[Flows]], and for the
 * same reason: a `topups` row and the on/off state file both hold a
 * name, never a class, so neither a tampered row nor a stale switch can
 * cause an arbitrary class to be instantiated. A method whose extension
 * is disabled or removed resolves to nothing and simply stops being
 * offered -- its history stays readable, because the report reads names.
 */
class PaymentMethods
{
    /** @var array<string, class-string<PaymentMethodInterface>>|null */
    private ?array $map = null;

    public function __construct(
        private Registry $extensions,
        private MethodState $state,
        private Feeder $feeder,
        private Logger $log
    ) {
    }

    /**
     * Every method contributed by a loaded, enabled extension.
     *
     * @return array<string, class-string<PaymentMethodInterface>>
     */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = [];

        foreach ($this->extensions->paymentMethods() as $slug => $classes) {
            foreach ($classes as $class) {
                if (!is_subclass_of($class, PaymentMethodInterface::class)) {
                    $this->log->warning(
                        "Payment method {$class} does not implement PaymentMethodInterface; skipped.",
                        ['extension' => $slug, 'handler' => $class]
                    );
                    continue;
                }

                $key = $class::key();

                // The key ends up in a state file and in every ledger row,
                // so the characters that would make either ambiguous are
                // refused here rather than discovered later.
                if (preg_match('/^[A-Za-z0-9._-]+$/', $key) !== 1) {
                    $this->log->warning(
                        "Payment method {$class} has an invalid key; skipped.",
                        ['extension' => $slug, 'handler' => $class, 'key' => $key]
                    );
                    continue;
                }

                if (isset($this->map[$key])) {
                    // Two extensions claiming one key would make the switch
                    // and the history ambiguous. First registered wins, and
                    // the loser is named so it can be fixed.
                    $this->log->warning(
                        "Payment method key '{$key}' is already taken; {$class} skipped.",
                        ['extension' => $slug, 'handler' => $class]
                    );
                    continue;
                }

                $this->map[$key] = $class;
            }
        }

        return $this->map;
    }

    /** @return class-string<PaymentMethodInterface>|null */
    public function find(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /** Builds one, or null when nothing answers to that name. */
    public function make(string $key): ?PaymentMethodInterface
    {
        $class = $this->find($key);

        if ($class === null) {
            return null;
        }

        $method = $this->feeder->make($class);

        return $method instanceof PaymentMethodInterface ? $method : null;
    }

    /**
     * What a customer may actually pick.
     *
     * Both gates have to pass: the admin has to have switched it on, and
     * the method has to say it can take money. Offering a button that
     * cannot work is worse than offering nothing, because the customer
     * finds out after deciding to pay.
     *
     * @return array<string, PaymentMethodInterface>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->all() as $key => $_) {
            $method = $this->make($key);

            if ($method !== null && $this->state->isEnabled($key) && $method->isConfigured()) {
                $available[$key] = $method;
            }
        }

        return $available;
    }
}
