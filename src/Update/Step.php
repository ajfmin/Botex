<?php

namespace Botex\Update;

/**
 * What happened to one target inside an `update:all` run.
 *
 * A batch is several independent updates, and one of them failing must
 * not hide what the others did -- so nothing is printed as it goes and
 * nothing throws out of the run. Each target ends up as one of these,
 * and the console prints the lot at the end.
 *
 * DEFERRED is the state worth knowing about: an extension whose new
 * release needs a core this install does not have *yet*, because the
 * core update is part of this same run and has not landed. It is not a
 * failure, it is a "run this again afterwards", and saying so is the
 * difference between one confusing error and one obvious next step.
 */
final class Step
{
    /** Applied. */
    public const UPDATED = 'updated';

    /** --dry-run: worked out, written nowhere. */
    public const PLANNED = 'planned';

    /** Refused or threw. */
    public const FAILED = 'failed';

    /** Waiting on the core update in this same run. */
    public const DEFERRED = 'deferred';

    private function __construct(
        public readonly string $target,
        public readonly string $state,
        public readonly ?Result $result = null,
        public readonly ?Plan $plan = null,
        public readonly string $message = ''
    ) {
    }

    public static function updated(string $target, Result $result): self
    {
        return new self($target, self::UPDATED, result: $result, plan: $result->plan);
    }

    public static function planned(string $target, Plan $plan): self
    {
        return new self($target, self::PLANNED, plan: $plan);
    }

    public static function failed(string $target, string $message, ?Plan $plan = null): self
    {
        return new self($target, self::FAILED, plan: $plan, message: $message);
    }

    public static function deferred(string $target, string $message, ?Plan $plan = null): self
    {
        return new self($target, self::DEFERRED, plan: $plan, message: $message);
    }

    public function isCore(): bool
    {
        return $this->target === 'core';
    }

    public function succeeded(): bool
    {
        return $this->state === self::UPDATED || $this->state === self::PLANNED;
    }

    /** One line for the batch summary. */
    public function line(): string
    {
        return match ($this->state) {
            self::UPDATED => '  + ' . ($this->result?->headline() ?? $this->target),
            self::PLANNED => '  ~ ' . ($this->plan?->describe() ?? $this->target),
            self::DEFERRED => '  . ' . $this->target . ': ' . $this->message,
            default => '  ! ' . $this->target . ': ' . $this->message,
        };
    }
}
