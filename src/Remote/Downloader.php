<?php

namespace Botex\Remote;

use Botex\Archive\Hash;
use Botex\Archive\Package;
use Botex\Archive\Version;

/**
 * Turns "slug at version" into a verified Package.
 *
 * Four things are checked before the bytes are handed on, and they catch
 * different failures:
 *
 *   1. the download's own sha256 against what the index published
 *      -- a truncated or substituted response
 *   2. the signature, when a key is configured (Signature)
 *      -- a compromised archive
 *   3. every payload file against the manifest (Package::verify)
 *      -- a repacked or edited payload
 *   4. the manifest's slug and version against what was asked for
 *      -- being handed a different package than the one requested
 *
 * The fourth is easy to forget and matters: without it, asking for
 * `Clock@1.2.0` and receiving a package that calls itself something else
 * would install under the name it claims.
 */
class Downloader
{
    private const DOWNLOAD = 'api/v1/download';

    public function __construct(
        private Client $client,
        private Catalog $catalog,
        private Signature $signature
    ) {
    }

    /**
     * Downloads and verifies a package.
     *
     * @param  string      $slug    package to fetch
     * @param  string|null $version exact version; the newest when omitted
     *
     * @throws NotFound        no such package or version
     * @throws RemoteException anything failed to verify
     */
    public function fetch(string $slug, ?string $version = null): Package
    {
        // Deliberately uncached. Browsing may show a stale index for an hour
        // and nobody is harmed, but resolving "newest" from a stale entry
        // would install the previous release while reporting success, and a
        // stale version list would reject a --version that does exist. One
        // extra request at the moment of installing is the right trade.
        $listing = $this->catalog->find($slug, fresh: true);
        $wanted = $version ?? $listing->version;

        if (!Version::isValid($wanted)) {
            throw new RemoteException("'{$wanted}' is not a valid version.");
        }

        // A version the archive does not list would 404 below anyway; this
        // says so with the list in hand, which is a better message.
        if ($listing->versions !== [] && !in_array($wanted, $listing->versions, true)) {
            throw new NotFound(
                "The archive has no {$slug} {$wanted}. Available: "
                    . implode(', ', array_slice($listing->versions, 0, 8))
            );
        }

        $bytes = $this->client->get(
            self::DOWNLOAD . '/' . rawurlencode($slug) . '/' . rawurlencode($wanted)
        );

        $this->verifyPublishedHash($slug, $wanted, $bytes);

        $this->signature->verify($bytes, $this->signatureFor($slug, $wanted));

        // Verifies the manifest against every payload file.
        $package = Package::fromString($bytes);

        if ($package->slug() !== $slug) {
            throw new RemoteException(
                "Asked the archive for '{$slug}' and got '{$package->slug()}'. Refusing it."
            );
        }

        if (!Version::equals($package->version(), $wanted)) {
            throw new RemoteException(
                "Asked the archive for {$slug} {$wanted} and got {$package->version()}. Refusing it."
            );
        }

        return $package;
    }

    /**
     * Checks the archive-level hash the index published for this release.
     *
     * Distinct from Package::verify(), which checks the payload against the
     * manifest *inside* the same file. This checks the file as a whole
     * against a value published separately, so a repack that rewrites both
     * payload and manifest consistently is still caught.
     *
     * @throws RemoteException
     */
    private function verifyPublishedHash(string $slug, string $version, string $bytes): void
    {
        try {
            $data = $this->client->json(
                'api/v1/package/' . rawurlencode($slug) . '/' . rawurlencode($version)
            );
        } catch (NotFound) {
            // A hub that does not expose per-version metadata still works:
            // the manifest and, if configured, the signature carry the
            // integrity guarantee.
            return;
        }

        $expected = (string) ($data['sha256'] ?? ($data['package']['sha256'] ?? ''));

        if ($expected === '') {
            return;
        }

        $actual = Hash::string($bytes);

        if (!Hash::matches($expected, $actual)) {
            throw new RemoteException(
                "The download of {$slug} {$version} does not match the hash the archive published. "
                    . 'Refusing it.'
            );
        }
    }

    /**
     * The detached signature for a release, when the archive serves one.
     *
     * Absence is not an error here; Signature::verify() decides whether an
     * unsigned package is acceptable, since only it knows whether a key is
     * configured.
     */
    private function signatureFor(string $slug, string $version): string
    {
        if (!$this->signature->required()) {
            return '';
        }

        try {
            return trim($this->client->get(
                self::DOWNLOAD . '/' . rawurlencode($slug) . '/' . rawurlencode($version) . '/signature'
            ));
        } catch (NotFound) {
            return '';
        }
    }

    /**
     * Saves a verified package to disk, for `--keep` or offline installs.
     *
     * @throws RemoteException
     */
    public function download(string $slug, string $version, string $destination): string
    {
        $package = $this->fetch($slug, $version);
        $file = rtrim($destination, '/\\') . '/' . Package::filename($slug, $package->version());

        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RemoteException("Could not create {$directory}");
        }

        // Re-serialised from the verified package rather than the raw
        // response, so what lands on disk is what passed verification.
        $writer = \Botex\Archive\Zip::writer();
        $writer->add(\Botex\Archive\Manifest::FILE, $package->manifest->toJson());

        foreach ($package->files() as $path) {
            $writer->add(\Botex\Archive\Manifest::PAYLOAD . '/' . $path, $package->file($path));
        }

        $writer->save($file);

        return $file;
    }
}
