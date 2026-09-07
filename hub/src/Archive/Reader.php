<?php

namespace Botex\Archive;

/**
 * Reads a zip produced by Writer, or by any ordinary zip tool.
 *
 * The central directory is parsed once up front, so listing an archive
 * costs nothing and a malformed one is rejected before a single byte is
 * inflated.
 *
 * SECURITY: this is the half that handles bytes from the network. Three
 * separate things are checked, because each catches a different attack:
 * the path (traversal), the declared size (a zip bomb, before inflating),
 * and the CRC plus real size (a corrupt or lying entry, after inflating).
 */
class Reader
{
    /** @var array<string, array<string, int|string>> entry name => header fields */
    private array $entries = [];

    private function __construct(
        private string $bytes
    ) {
        $this->parse();
    }

    /** @throws ArchiveException */
    public static function open(string $file): self
    {
        if (!is_file($file)) {
            throw new ArchiveException("No archive at {$file}");
        }

        $size = (int) filesize($file);

        if ($size > Zip::MAX_TOTAL_BYTES) {
            throw new ArchiveException('Archive is larger than the limit.');
        }

        $bytes = file_get_contents($file);

        if ($bytes === false) {
            throw new ArchiveException("Could not read {$file}");
        }

        return new self($bytes);
    }

    /** @throws ArchiveException */
    public static function fromString(string $bytes): self
    {
        if (strlen($bytes) > Zip::MAX_TOTAL_BYTES) {
            throw new ArchiveException('Archive is larger than the limit.');
        }

        return new self($bytes);
    }

    /**
     * Locates the end-of-central-directory record and walks the directory.
     *
     * The EOCD sits at the very end of the file, but it carries an optional
     * trailing comment of up to 64 KB, so its exact position is not known
     * and it has to be searched for backwards from the end.
     *
     * @throws ArchiveException
     */
    private function parse(): void
    {
        $length = strlen($this->bytes);

        // 22 bytes is the EOCD with an empty comment: anything shorter
        // cannot be a zip at all.
        if ($length < 22) {
            throw new ArchiveException('Not a zip archive: too short.');
        }

        $eocd = $this->findEocd($length);

        $fields = unpack('vdisk/vstart/vhere/vtotal/Vsize/Voffset/vcomment', substr($this->bytes, $eocd + 4, 18));

        if ($fields === false) {
            throw new ArchiveException('Corrupt archive: unreadable directory record.');
        }

        // Zip64 and multi-disk archives are out of scope; a package that
        // needs either is not a package, and half-supporting them would be
        // worse than refusing them.
        if ($fields['disk'] !== 0 || $fields['start'] !== 0) {
            throw new ArchiveException('Multi-disk archives are not supported.');
        }

        if ($fields['total'] === 0xFFFF || $fields['size'] === 0xFFFFFFFF) {
            throw new ArchiveException('Zip64 archives are not supported.');
        }

        $offset = $fields['offset'];
        $end = $offset + $fields['size'];

        if ($offset < 0 || $end > $length) {
            throw new ArchiveException('Corrupt archive: directory lies outside the file.');
        }

        $position = $offset;

        for ($index = 0; $index < $fields['total']; $index++) {
            $position = $this->parseEntry($position, $end);
        }
    }

    /** @throws ArchiveException */
    private function findEocd(int $length): int
    {
        // Scan back over the largest possible comment plus the record.
        $limit = min($length, 65535 + 22);

        for ($offset = $length - 22; $offset >= $length - $limit && $offset >= 0; $offset--) {
            if (substr($this->bytes, $offset, 4) !== Zip::signatureEocd()) {
                continue;
            }

            // The comment length must account for exactly the remaining
            // bytes, which rules out a false positive from file data that
            // happens to spell PK\x05\x06.
            $comment = unpack('v', substr($this->bytes, $offset + 20, 2));

            if ($comment !== false && $offset + 22 + $comment[1] === $length) {
                return $offset;
            }
        }

        throw new ArchiveException('Not a zip archive: no directory record found.');
    }

    /**
     * Reads one central directory entry and returns the next position.
     *
     * @throws ArchiveException
     */
    private function parseEntry(int $position, int $end): int
    {
        if ($position + 46 > $end) {
            throw new ArchiveException('Corrupt archive: truncated directory entry.');
        }

        if (substr($this->bytes, $position, 4) !== Zip::signatureCentral()) {
            throw new ArchiveException('Corrupt archive: bad directory signature.');
        }

        $header = unpack(
            'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/'
                . 'vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
            substr($this->bytes, $position + 4, 42)
        );

        if ($header === false) {
            throw new ArchiveException('Corrupt archive: unreadable directory entry.');
        }

        $name = substr($this->bytes, $position + 46, $header['nameLength']);

        if (strlen($name) !== $header['nameLength']) {
            throw new ArchiveException('Corrupt archive: truncated entry name.');
        }

        $next = $position + 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];

        // A directory entry, which zip records as a zero-length name ending
        // in a slash. Skipped: extraction recreates folders from file paths,
        // so the entries carry no information and validating them as paths
        // would reject a legitimate archive.
        if (str_ends_with($name, '/')) {
            return $next;
        }

        // Validated here, at parse time, so a hostile path is refused
        // before anything asks to extract it.
        $safe = Zip::path($name);

        if ($header['size'] > Zip::MAX_ENTRY_BYTES) {
            throw new ArchiveException("Archive entry '{$safe}' is too large.");
        }

        $this->entries[$safe] = [
            'method' => $header['method'],
            'crc' => $header['crc'],
            'compressed' => $header['compressed'],
            'size' => $header['size'],
            'offset' => $header['offset'],
        ];

        return $next;
    }

    /** @return array<string> entry paths, sorted */
    public function names(): array
    {
        $names = array_keys($this->entries);
        sort($names);

        return $names;
    }

    public function has(string $path): bool
    {
        return isset($this->entries[Zip::path($path)]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** Total uncompressed size, as declared by the directory. */
    public function size(): int
    {
        return array_sum(array_column($this->entries, 'size'));
    }

    /**
     * Inflates one entry and verifies it.
     *
     * @throws ArchiveException
     */
    public function get(string $path): string
    {
        $name = Zip::path($path);

        if (!isset($this->entries[$name])) {
            throw new ArchiveException("Archive has no entry '{$name}'.");
        }

        $entry = $this->entries[$name];
        $offset = (int) $entry['offset'];

        if (substr($this->bytes, $offset, 4) !== Zip::signatureLocal()) {
            throw new ArchiveException("Corrupt archive: bad header for '{$name}'.");
        }

        $local = unpack(
            'vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength',
            substr($this->bytes, $offset + 4, 26)
        );

        if ($local === false) {
            throw new ArchiveException("Corrupt archive: unreadable header for '{$name}'.");
        }

        // Data begins after the local header, its name, and its extra
        // field, whose lengths are the local header's own -- not the
        // central directory's, which may legitimately differ.
        $start = $offset + 30 + $local['nameLength'] + $local['extraLength'];
        $compressed = (int) $entry['compressed'];

        if ($start + $compressed > strlen($this->bytes)) {
            throw new ArchiveException("Corrupt archive: '{$name}' runs past the end.");
        }

        $data = substr($this->bytes, $start, $compressed);

        $contents = match ((int) $entry['method']) {
            0 => $data,
            8 => $this->inflate($data, $name),
            default => throw new ArchiveException(
                "Archive entry '{$name}' uses an unsupported compression method."
            ),
        };

        // Both checks matter and neither implies the other: the size catches
        // a declared-small-expands-huge bomb, the CRC catches corruption or
        // a tampered payload.
        if (strlen($contents) !== (int) $entry['size']) {
            throw new ArchiveException("Archive entry '{$name}' has the wrong size.");
        }

        if (crc32($contents) !== (int) $entry['crc']) {
            throw new ArchiveException("Archive entry '{$name}' failed its checksum.");
        }

        return $contents;
    }

    /** @throws ArchiveException */
    private function inflate(string $data, string $name): string
    {
        if (!Zip::canDeflate()) {
            throw new ArchiveException(
                "Cannot read '{$name}': this PHP has no zlib, so deflated entries are unreadable."
            );
        }

        // Suppressed because a malformed stream raises a warning as well as
        // returning false, and the false is what we act on.
        $inflated = @gzinflate($data);

        if ($inflated === false) {
            throw new ArchiveException("Archive entry '{$name}' could not be decompressed.");
        }

        return $inflated;
    }

    /**
     * Extracts everything under a directory.
     *
     * Every path was validated at parse time, and is re-checked against the
     * resolved destination here, since realpath() is the only thing that
     * can see through a symlink already sitting in the target.
     *
     * @param  string      $destination folder to write into
     * @param  string|null $only        extract just this prefix, e.g. 'files/'
     * @return array<string> paths written, relative to $destination
     *
     * @throws ArchiveException
     */
    public function extractTo(string $destination, ?string $only = null): array
    {
        if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new ArchiveException("Could not create {$destination}");
        }

        $root = realpath($destination);

        if ($root === false) {
            throw new ArchiveException("Could not resolve {$destination}");
        }

        $root = str_replace('\\', '/', $root);
        $prefix = $only === null ? null : rtrim(Zip::path($only), '/') . '/';
        $written = [];

        foreach ($this->names() as $name) {
            $relative = $name;

            if ($prefix !== null) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }

                $relative = substr($name, strlen($prefix));

                if ($relative === '') {
                    continue;
                }
            }

            $target = $root . '/' . $relative;
            $directory = dirname($target);

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new ArchiveException("Could not create {$directory}");
            }

            // The last line of defence: the parent must actually resolve
            // inside the destination. Catches a symlink planted in the
            // target directory, which no amount of string checking on the
            // archive's own paths can see.
            $resolved = realpath($directory);

            if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved) . '/', $root . '/')) {
                throw new ArchiveException("Refusing to write '{$name}' outside {$destination}.");
            }

            if (@file_put_contents($target, $this->get($name), LOCK_EX) === false) {
                throw new ArchiveException("Could not write {$target}");
            }

            $written[] = $relative;
        }

        return $written;
    }

    /**
     * sha256 of one entry, without keeping the inflated bytes around.
     *
     * @throws ArchiveException
     */
    public function hash(string $path): string
    {
        return hash('sha256', $this->get($path));
    }
}
