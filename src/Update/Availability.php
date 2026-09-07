<?php

namespace Botex\Update;

use Botex\Botex;
use Botex\Extension\Registry;
use Botex\Remote\Catalog;
use Botex\Remote\Listing;

/**
 * What updates are available, answered without any network access.
 *
 * Both read-only surfaces -- the /admin Updates section and panel.php --
 * ask this, so they can never disagree about what is available. Neither
 * may write a file or fetch a URL: an admin account is a Telegram account,
 * and a stolen one must not be able to install code. Applying an update
 * is the CLI's job, where the person running it is on the host already.
 *
 * Everything here reads the cache written by the last CLI run. That means
 * it can be stale, and can be *unknown* -- which is reported as unknown
 * rather than as "up to date", because claiming the latter without having
 * looked would be a lie in the one direction that matters.
 */
class Availability
{
    public function __construct(
        private Catalog $catalog,
        private Registry $registry,
        private Inventory $inventory
    ) {
    }

    /** Whether an archive is configured at all. */
    public function configured(): bool
    {
        return $this->catalog->configured();
    }

    /**
     * Extensions with a newer version in the archive.
     *
     * @return array<array{slug: string, name: string, installed: string, available: string}>
     */
    public function extensions(): array
    {
        $listings = $this->catalog->cachedExtensions();

        if ($listings === null) {
            return [];
        }

        $outdated = [];

        foreach ($this->registry->all() as $manifest) {
            $listing = $listings[$manifest->slug] ?? null;

            if ($listing === null || !$listing->isNewerThan($manifest->version)) {
                continue;
            }

            $outdated[] = [
                'slug' => $manifest->slug,
                'name' => $manifest->name,
                'installed' => $manifest->version,
                'available' => $listing->version,
            ];
        }

        return $outdated;
    }

    /** The core release on offer, when it is newer than what is running. */
    public function core(): ?Listing
    {
        $listing = $this->catalog->cachedCore();

        if ($listing === null || !$listing->isNewerThan(Botex::VERSION)) {
            return null;
        }

        return $listing;
    }

    /**
     * Whether the archive has been looked at recently enough to say.
     *
     * False means "nobody has run a check yet", which every caller must
     * present differently from "no updates".
     */
    public function known(): bool
    {
        return $this->catalog->cachedExtensions() !== null;
    }

    /** Core files the operator has edited, which would block a core update. */
    public function conflicts(): array
    {
        return $this->inventory->isUsable() ? $this->inventory->dirty() : [];
    }

    /** Whether a baseline exists to measure local changes against. */
    public function baselined(): bool
    {
        return $this->inventory->isUsable();
    }

    /** Total count for a badge, core counting as one. */
    public function count(): int
    {
        return count($this->extensions()) + ($this->core() !== null ? 1 : 0);
    }
}
