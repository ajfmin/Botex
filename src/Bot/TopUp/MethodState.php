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
 *
 * **A write that fails throws.** This used to ignore what
 * `file_put_contents` returned, and the result was the worst shape a bug
 * can take: an admin pressed Turn on, the object updated itself in
 * memory, the screen redrew showing the method on, the toast said it was
 * on -- and the file had not changed, so the next request read it back
 * off. Everything agreed except the only copy that lasts. Nothing is
 * kept in memory now until it is on disk, and a caller that cannot
 * persist a decision is told so rather than being allowed to report
 * success.
 */
class MethodState
{
    /** Thrown when a decision could not be made durable. */
    public const UNWRITABLE = 'The payment method switch could not be saved';

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
        $next = $this->state;
        unset($next[$key]);

        $this->write($next);

        $this->state = $next;
    }

    /**
     * Whether a decision could be saved, without making one.
     *
     * For `doctor` and anything else that wants to warn before an admin
     * finds out by pressing a button that appears to work.
     */
    public function isWritable(): bool
    {
        if (is_file($this->file)) {
            return is_writable($this->file);
        }

        $dir = dirname($this->file);

        return is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
    }

    public function path(): string
    {
        return $this->file;
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

    /**
     * Records a decision, disk first.
     *
     * The in-memory copy is replaced only once the write has succeeded.
     * Doing it the other way round is what let a failed save look like a
     * working switch: everything that asked this object afterwards --
     * the screen, the toast, the customer-facing list within that same
     * request -- was told the new value, and only the next request found
     * out it had never been saved.
     */
    private function set(string $key, bool $enabled): void
    {
        $next = $this->state;
        $next[$key]['enabled'] = $enabled;
        $next[$key]['changed_at'] = gmdate('c');

        $this->write($next);

        $this->state = $next;
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

    /**
     * Puts the given state on disk, or throws saying why not.
     *
     * Every step is checked, because every one of them can fail on a
     * real host for a reason that has nothing to do with this code: a
     * `storage/` owned by whoever ran the installer while the webhook
     * runs as the web server, a full disk, a read-only mount. Warnings
     * are suppressed and re-raised as one exception, since a PHP warning
     * on a webhook goes to a log nobody is reading and the request
     * carries on as though it had worked.
     *
     * @param array<string, array<string, mixed>> $state
     */
    private function write(array $state): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(self::UNWRITABLE . ": {$dir} does not exist and could not be created.");
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException(self::UNWRITABLE . ': the state could not be encoded.');
        }

        $written = @file_put_contents($this->file, $json, LOCK_EX);

        if ($written === false || $written !== strlen($json)) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            throw new \RuntimeException(
                self::UNWRITABLE . " to {$this->file}: {$reason}"
            );
        }
    }
}
