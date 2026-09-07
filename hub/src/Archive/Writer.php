<?php

namespace Botex\Archive;

/**
 * Builds a zip in memory, then writes it once.
 *
 * In memory because a package is small by definition -- an extension
 * folder or a core tree, both a few hundred kilobytes -- and a single
 * atomic write at the end means a crash mid-build cannot leave a
 * half-written .botex file that looks installable.
 *
 * @see Zip for why this is not ZipArchive
 */
class Writer
{
    /** Accumulated local-header-plus-data blocks, in write order. */
    private string $body = '';

    /** Central directory entries, built alongside the body. */
    private string $directory = '';

    private int $entries = 0;

    /** Guards against the same path being added twice. */
    private array $seen = [];

    private int $uncompressed = 0;

    /**
     * Adds a file.
     *
     * @param  string  $path     path inside the archive
     * @param  string  $contents raw bytes
     * @param  int|null $modified unix timestamp; now when omitted
     *
     * @throws ArchiveException
     */
    public function add(string $path, string $contents, ?int $modified = null): self
    {
        $name = Zip::path($path);

        if (isset($this->seen[$name])) {
            throw new ArchiveException("Archive already contains '{$name}'.");
        }

        if ($this->entries >= Zip::maxEntries()) {
            throw new ArchiveException('Archive has too many entries.');
        }

        $size = strlen($contents);
        $this->uncompressed += $size;

        if ($this->uncompressed > Zip::MAX_TOTAL_BYTES) {
            throw new ArchiveException('Archive contents exceed the size limit.');
        }

        $this->seen[$name] = true;

        // Deflate unless it makes things worse. It does for anything already
        // compressed (a png, a gzipped blob), where the deflate wrapper adds
        // a few bytes to an incompressible stream, so the smaller of the two
        // is kept and the method recorded accordingly.
        $method = Zip::methodStore();
        $data = $contents;

        if ($size > 0 && Zip::canDeflate()) {
            $deflated = gzdeflate($contents, 9);

            if ($deflated !== false && strlen($deflated) < $size) {
                $method = Zip::methodDeflate();
                $data = $deflated;
            }
        }

        $crc = crc32($contents);
        [$date, $time] = Zip::dosTime($modified ?? time());

        // Offset of this entry's local header, which the central directory
        // has to point at. Taken before the header is appended.
        $offset = strlen($this->body);

        $header = pack(
            'vvvvvVVVvv',
            20,                 // version needed: 2.0, which is deflate
            0,                  // general purpose flags: none, sizes are known up front
            $method,
            $time,
            $date,
            $crc,
            strlen($data),      // compressed size
            $size,              // uncompressed size
            strlen($name),
            0                   // extra field length
        );

        $this->body .= Zip::signatureLocal() . $header . $name . $data;

        $this->directory .= Zip::signatureCentral() . pack(
            'vvvvvvVVVvvvvvVV',
            20,                 // version made by
            20,                 // version needed
            0,                  // flags
            $method,
            $time,
            $date,
            $crc,
            strlen($data),
            $size,
            strlen($name),
            0,                  // extra
            0,                  // comment
            0,                  // disk number
            0,                  // internal attributes
            // External attributes: 0644 in the high word, which is what a
            // unix host reads permissions from. Without it some tools
            // extract a package as 0000 and the bot cannot read its own
            // update.
            0x81A40000,
            $offset
        ) . $name;

        $this->entries++;

        return $this;
    }

    /**
     * Adds every file under a directory, recursively.
     *
     * @param  string   $directory  folder on disk
     * @param  string   $prefix     path inside the archive to nest under
     * @param  callable|null $filter fn(string $relative): bool, false to skip
     *
     * @throws ArchiveException
     */
    public function addDirectory(string $directory, string $prefix = '', ?callable $filter = null): self
    {
        $root = realpath($directory);

        if ($root === false || !is_dir($root)) {
            throw new ArchiveException("Not a directory: {$directory}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Sorted, so the same folder always produces the same archive and a
        // package hash is reproducible. Iteration order is filesystem
        // dependent otherwise.
        $files = [];

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $absolute = $item->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));

            if ($filter !== null && !$filter($relative)) {
                continue;
            }

            $files[$relative] = $absolute;
        }

        ksort($files);

        foreach ($files as $relative => $absolute) {
            $contents = file_get_contents($absolute);

            if ($contents === false) {
                throw new ArchiveException("Could not read {$absolute}");
            }

            $this->add(
                $prefix === '' ? $relative : rtrim($prefix, '/') . '/' . $relative,
                $contents,
                (int) filemtime($absolute)
            );
        }

        return $this;
    }

    /** The finished archive as bytes. */
    public function toString(): string
    {
        $eocd = Zip::signatureEocd() . pack(
            'vvvvVVv',
            0,                          // this disk
            0,                          // disk the directory starts on
            $this->entries,             // entries on this disk
            $this->entries,             // entries total
            strlen($this->directory),   // directory size
            strlen($this->body),        // directory offset
            0                           // comment length
        );

        return $this->body . $this->directory . $eocd;
    }

    /**
     * Writes the archive to disk atomically.
     *
     * Via a temp file in the same directory plus a rename, so a reader can
     * never observe a partial archive: rename is atomic within a
     * filesystem, a streaming write is not.
     *
     * @throws ArchiveException
     */
    public function save(string $file): int
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ArchiveException("Could not create {$directory}");
        }

        $bytes = $this->toString();
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $bytes, LOCK_EX) === false) {
            throw new ArchiveException("Could not write {$temporary}");
        }

        // Windows will not rename onto an existing file.
        if (is_file($file) && !@unlink($file)) {
            @unlink($temporary);

            throw new ArchiveException("Could not replace {$file}");
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new ArchiveException("Could not move the archive into {$file}");
        }

        return strlen($bytes);
    }

    public function count(): int
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === 0;
    }
}
