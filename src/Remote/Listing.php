<?php

namespace Botex\Remote;

use Botex\Archive\Version;

/**
 * One package as the archive describes it in a listing.
 *
 * A read-only view of someone else's JSON, so every field is defaulted and
 * nothing is trusted: a hub could omit anything, and this must still render
 * in a console table or a Telegram message without a null blowing up
 * mid-render.
 */
class Listing
{
    /**
     * @param array<string,string> $commands install|update|remove
     * @param array<string,string> $requires botex|php
     * @param array<string>        $versions newest first
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $type,
        public readonly array $commands,
        public readonly array $requires,
        public readonly array $versions,
        public readonly int $downloads,
        public readonly string $updatedAt,
        public readonly string $author,
        public readonly string $changelog,
        public readonly string $readme
    ) {
    }

    public static function fromArray(array $data): self
    {
        $versions = array_values(array_filter(
            array_map('strval', (array) ($data['versions'] ?? [])),
            [Version::class, 'isValid']
        ));

        return new self(
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ($data['slug'] ?? '')),
            version: (string) ($data['version'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            type: (string) ($data['type'] ?? 'extension'),
            commands: array_map('strval', array_filter((array) ($data['commands'] ?? []), 'is_scalar')),
            requires: array_map('strval', array_filter((array) ($data['requires'] ?? []), 'is_scalar')),
            versions: Version::sortDescending($versions),
            downloads: (int) ($data['downloads'] ?? 0),
            updatedAt: (string) ($data['updated_at'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            changelog: (string) ($data['changelog'] ?? ''),
            readme: (string) ($data['readme'] ?? '')
        );
    }

    public function isCore(): bool
    {
        return $this->type === 'core';
    }

    /** Whether this listing is usable: a slug and a version at minimum. */
    public function isValid(): bool
    {
        return $this->slug !== '' && Version::isValid($this->version);
    }

    /** Whether the archive offers something newer than $installed. */
    public function isNewerThan(string $installed): bool
    {
        if (!Version::isValid($installed) || !Version::isValid($this->version)) {
            return false;
        }

        return Version::greaterThan($this->version, $installed);
    }

    public function command(string $which): string
    {
        return $this->commands[$which] ?? '';
    }

    /** Description trimmed for a one-line table cell. */
    public function short(int $length = 60): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->description) ?? '');

        if ($text === '' || mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 1) . '…';
    }
}
