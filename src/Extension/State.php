<?php

namespace Botex\Extension;

/**
 * Tracks which extensions are enabled.
 *
 * Kept in storage/ rather than in extension.json so updating an
 * extension (replacing its folder) never clobbers its enabled flag.
 * An extension not present in the file is treated as enabled, so a
 * freshly dropped-in folder works without a write.
 */
class State
{
    private array $state;

    public function __construct(
        private string $file
    ) {
        $this->state = $this->read();
    }

    public function isEnabled(string $slug): bool
    {
        return $this->state[$slug]['enabled'] ?? true;
    }

    public function enable(string $slug): void
    {
        $this->state[$slug]['enabled'] = true;
        $this->write();
    }

    public function disable(string $slug): void
    {
        $this->state[$slug]['enabled'] = false;
        $this->write();
    }

    public function forget(string $slug): void
    {
        unset($this->state[$slug]);
        $this->write();
    }

    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Saves the state, or throws saying why not.
     *
     * Checked rather than fire-and-forget: an unwritable `storage/` --
     * usually one owned by whoever ran the installer, on a host where
     * the web server is somebody else -- used to leave every caller
     * believing an extension had been enabled or disabled when nothing
     * had been written at all. A decision nobody can save is not a
     * decision, and saying so beats being contradicted by the next
     * request.
     */
    private function write(): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Extension state could not be saved: {$dir} could not be created.");
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = $json === false ? false : @file_put_contents($this->file, $json, LOCK_EX);

        if ($written === false || $written !== strlen((string) $json)) {
            throw new \RuntimeException(
                "Extension state could not be saved to {$this->file}: "
                    . (error_get_last()['message'] ?? 'unknown error')
            );
        }
    }
}
