<?php

namespace Botex\Bot\TopUp;

/**
 * Which payment methods are switched on.
 *
 * A file rather than a table, for the same reason extension state is
 * one: an operator has to be able to turn a broken payment method off
 * from the console on a bot whose database is the thing that is broken,
 * and this is the switch they will reach for at 3am.
 *
 * Kept in `storage/` rather than in the extension that owns the method,
 * so updating or reinstalling that extension never silently flips a
 * payment route back on.
 *
 * **Absent means off.** This is the opposite of `Extension\State`, and
 * the difference is money: an extension appearing on disk and working is
 * a convenience, whereas a payment route appearing and immediately
 * taking customers' money because nobody was asked is not. A new method
 * waits to be switched on.
 */
class MethodState
{
    /** @var array<string, array<string, mixed>> */
    private array $state;

    public function __construct(
        private string $file
    ) {
        $this->state = $this->read();
    }

    public function isEnabled(string $key): bool
    {
        return (bool) ($this->state[$key]['enabled'] ?? false);
    }

    /** Whether an admin has ever made a decision about this method. */
    public function isKnown(string $key): bool
    {
        return array_key_exists($key, $this->state);
    }

    public function enable(string $key): void
    {
        $this->set($key, true);
    }

    public function disable(string $key): void
    {
        $this->set($key, false);
    }

    /** Flips it, and reports the state it landed in. */
    public function toggle(string $key): bool
    {
        $enabled = !$this->isEnabled($key);

        $this->set($key, $enabled);

        return $enabled;
    }

    public function forget(string $key): void
    {
        unset($this->state[$key]);
        $this->write();
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        $all = [];

        foreach ($this->state as $key => $row) {
            $all[$key] = (bool) ($row['enabled'] ?? false);
        }

        return $all;
    }

    private function set(string $key, bool $enabled): void
    {
        $this->state[$key]['enabled'] = $enabled;
        $this->state[$key]['changed_at'] = gmdate('c');

        $this->write();
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    private function write(): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->file,
            json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
