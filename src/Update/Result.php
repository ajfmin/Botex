<?php

namespace Botex\Update;

/**
 * What an update actually did.
 *
 * Returned rather than printed, so the console formats it for a terminal
 * and the admin panel can describe the same operation without either of
 * them re-deriving what happened.
 */
class Result
{
    public function __construct(
        public readonly string $slug,
        public readonly string $from,
        public readonly string $to,
        public readonly Plan $plan,
        public readonly string $backup,
        /** An install()/migration problem worth reporting, or null. */
        public readonly ?string $migrated = null,
        public readonly bool $enabled = true,
        /** Set when a core update needs composer install run afterwards. */
        public readonly bool $dependenciesChanged = false,
        /**
         * Set when this was core:update --reset.
         *
         * Worth carrying rather than inferring from the plan: a reset
         * that happened to have nothing to write is still a reset, and
         * still dropped every adoption. Reporting it as an ordinary
         * update would be the one sentence an operator needed to see.
         */
        public readonly bool $wasReset = false,
        /** Adopted files a reset overwrote. */
        public readonly int $adoptionsDropped = 0
    ) {
    }

    public function wasInstall(): bool
    {
        return $this->from === '';
    }

    /** e.g. "Updated Clock 1.2.0 -> 1.3.0" */
    public function headline(): string
    {
        if ($this->wasReset) {
            return "Reset {$this->slug} to {$this->to}.";
        }

        if ($this->wasInstall()) {
            return "Installed {$this->slug} {$this->to}.";
        }

        if ($this->from === $this->to) {
            return "Reinstalled {$this->slug} {$this->to}.";
        }

        return "Updated {$this->slug} {$this->from} -> {$this->to}.";
    }

    /**
     * Everything worth telling the operator, in order.
     *
     * @return array<string>
     */
    public function lines(): array
    {
        $lines = [$this->headline(), '  ' . $this->plan->summary()];

        if (!$this->enabled) {
            $lines[] = '  it is still disabled; enable it with ext:enable ' . $this->slug;
        }

        if ($this->migrated !== null) {
            $lines[] = '  ! its install() reported: ' . $this->migrated;
        }

        if ($this->wasReset) {
            $lines[] = '  the baseline is now this release exactly';
        }

        // Said for a force as well as a reset: either way the operator's
        // version of those files is in the backup and nowhere else, and
        // the adoption that was protecting them is gone.
        if ($this->adoptionsDropped > 0) {
            $lines[] = '  ' . $this->adoptionsDropped
                . ' adopted file(s) were overwritten; core:adopt again to keep new ones';
        }

        if ($this->dependenciesChanged) {
            $lines[] = '  ! composer.json changed; run composer install';
        }

        if ($this->backup !== '') {
            $lines[] = '  the previous version is in storage/backups/' . $this->backup;
        }

        return $lines;
    }
}
