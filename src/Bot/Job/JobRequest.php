<?php

namespace Botex\Bot\Job;

/**
 * A job someone wants scheduled: which handler, what data, on what
 * schedule.
 *
 * The same shape as Run, and for the same reason: the caller builds
 * an intent, and the layer underneath decides how it is stored. See
 * [[JobService]] for what turns this into a row.
 */
class JobRequest
{
    /** Reserved slug for jobs that ship with core. */
    public const CORE = 'core';

    /**
     * @param array<string, mixed> $data        anything json-encodable
     * @param string|null          $key         dedupe key; a second
     *                                          request with the same key
     *                                          updates rather than adds
     * @param int|null             $maxAttempts null defers to config
     */
    private function __construct(
        public readonly string $extension,
        public readonly string $job,
        public readonly Schedule $schedule,
        public readonly array $data = [],
        public readonly ?string $key = null,
        public readonly ?int $maxAttempts = null
    ) {
    }

    /**
     * @param string               $extension extension slug, or self::CORE
     * @param string               $job       the handler's name()
     * @param array<string, mixed> $data
     */
    public static function to(
        string $extension,
        string $job,
        Schedule $schedule,
        array $data = []
    ): self {
        return new self(
            self::validName($extension, 'extension slug'),
            self::validName($job, 'job name'),
            $schedule,
            $data
        );
    }

    public function with(array $data): self
    {
        return new self(
            $this->extension,
            $this->job,
            $this->schedule,
            $data,
            $this->key,
            $this->maxAttempts
        );
    }

    public function set(string $key, mixed $value): self
    {
        return $this->with(array_merge($this->data, [$key => $value]));
    }

    public function on(Schedule $schedule): self
    {
        return new self(
            $this->extension,
            $this->job,
            $schedule,
            $this->data,
            $this->key,
            $this->maxAttempts
        );
    }

    /**
     * Names the job, so scheduling it again replaces rather than adds.
     *
     * The way to keep one nightly digest instead of one per deploy.
     */
    public function keyed(string $key): self
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('A job key cannot be blank.');
        }

        return new self(
            $this->extension,
            $this->job,
            $this->schedule,
            $this->data,
            trim($key),
            $this->maxAttempts
        );
    }

    /** How many times a failing run is retried before it gives up. */
    public function attempts(int $max): self
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('A job needs at least one attempt.');
        }

        return new self(
            $this->extension,
            $this->job,
            $this->schedule,
            $this->data,
            $this->key,
            $max
        );
    }

    public function isCore(): bool
    {
        return $this->extension === self::CORE;
    }

    /** How this job is addressed in the Jobs allowlist. */
    public function handlerKey(): string
    {
        return $this->extension . ':' . $this->job;
    }

    /**
     * Slugs and job names are joined by a colon into a lookup key, so a
     * colon in either would make that key ambiguous. Rejected at build
     * time rather than when the worker gets there.
     */
    private static function validName(string $value, string $label): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \InvalidArgumentException(
                "Invalid {$label} '{$value}': use letters, digits, dot, underscore or dash."
            );
        }

        return $value;
    }
}
