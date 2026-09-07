<?php

namespace Botex\Extension;

/**
 * Per-extension settings, editable at runtime.
 *
 * Defaults ship with the extension in settings.json; overrides live in
 * storage/extension-settings/<slug>.json. Since the extension folder is
 * replaced wholesale on update and storage is not, an update can add new
 * defaults without ever discarding what the admin configured.
 */
class Settings
{
    private array $defaults;

    private array $overrides;

    public function __construct(
        private string $slug,
        private string $extensionPath,
        private string $storagePath
    ) {
        $this->defaults = $this->readJson($this->extensionPath . '/settings.json');
        $this->overrides = $this->readJson($this->file());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->overrides[$key] ?? $this->defaults[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        // Only keys the extension declares are writable, so a typo in
        // the panel cannot quietly create a setting nothing reads.
        if (!array_key_exists($key, $this->defaults)) {
            throw new \RuntimeException(
                "{$this->slug} has no setting '{$key}'."
            );
        }

        $this->overrides[$key] = $value;
        $this->write();
    }

    /** Drops an override, restoring the shipped default. */
    public function reset(string $key): void
    {
        unset($this->overrides[$key]);
        $this->write();
    }

    /** Effective values: defaults with overrides applied. */
    public function all(): array
    {
        return [...$this->defaults, ...$this->overrides];
    }

    /** @return array<string> keys the extension declares */
    public function keys(): array
    {
        return array_keys($this->defaults);
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides);
    }

    /** Deletes stored overrides. Called when an extension is removed. */
    public function forget(): void
    {
        if (is_file($this->file())) {
            unlink($this->file());
        }

        $this->overrides = [];
    }

    private function file(): string
    {
        return $this->storagePath . '/' . $this->slug . '.json';
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    private function write(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }

        file_put_contents(
            $this->file(),
            json_encode($this->overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
