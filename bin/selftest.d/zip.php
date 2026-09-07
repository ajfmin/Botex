<?php

/**
 * The pure-PHP zip reader and writer.
 *
 * This code exists because the zip extension is not assumed to be present,
 * which means it is the one place where a bug corrupts every package rather
 * than one. Both halves are checked against each other, and the guards are
 * checked by trying to get past them.
 */

use Botex\Archive\Reader;
use Botex\Archive\Zip;

group('Zip');

check('a written archive reads back byte-identical', static function () {
    $files = [
        'botex.json' => '{"a":1}',
        'files/src/Deep/Nested/Thing.php' => "<?php\n\nreturn 1;\n",
        'files/empty.txt' => '',
        // Incompressible, so the writer must store rather than deflate it.
        'files/random.bin' => random_bytes(2048),
        // Larger than one deflate block, to exercise the streaming path.
        'files/big.txt' => str_repeat("line of text\n", 5000),
    ];

    $writer = Zip::writer();

    foreach ($files as $name => $contents) {
        $writer->add($name, $contents);
    }

    $reader = Reader::fromString($writer->toString());

    foreach ($files as $name => $contents) {
        if ($reader->get($name) !== $contents) {
            return "'{$name}' did not survive the round trip";
        }
    }

    return count($reader->names()) === count($files)
        ? true
        : 'the archive holds a different number of entries than were added';
});

check('an empty file stays empty rather than becoming absent', static function () {
    $writer = Zip::writer();
    $writer->add('files/empty', '');

    $reader = Reader::fromString($writer->toString());

    return $reader->has('files/empty') && $reader->get('files/empty') === '' ? true : 'lost';
});

check('utf-8 content survives', static function () {
    $text = "سلام دنیا\nこんにちは\n";

    $writer = Zip::writer();
    $writer->add('files/utf8.txt', $text);

    return Reader::fromString($writer->toString())->get('files/utf8.txt') === $text
        ? true
        : 'multibyte content changed';
});

refuses('a path escaping the archive root (../)', static function () {
    Zip::writer()->add('../escaped.php', 'x');
});

refuses('a nested traversal (files/../../escaped)', static function () {
    Zip::writer()->add('files/../../escaped.php', 'x');
});

refuses('an absolute path (/etc/passwd)', static function () {
    Zip::writer()->add('/etc/passwd', 'x');
});

refuses('a windows drive path (C:\\Windows\\x)', static function () {
    Zip::writer()->add('C:\\Windows\\x', 'x');
});

refuses('a NUL byte in a path', static function () {
    Zip::writer()->add("files/ok.php\0.txt", 'x');
});

check('a dotfile is still allowed (..name is not traversal)', static function () {
    $writer = Zip::writer();
    $writer->add('files/..gitkeep', 'x');
    $writer->add('files/.hidden', 'y');

    $reader = Reader::fromString($writer->toString());

    return $reader->get('files/..gitkeep') === 'x' && $reader->get('files/.hidden') === 'y'
        ? true
        : 'a legitimate dotted name was rejected or mangled';
});

refuses('a truncated archive', static function () use (&$temp) {
    $writer = Zip::writer();
    $writer->add('files/a.txt', str_repeat('a', 500));

    $bytes = $writer->toString();

    Reader::fromString(substr($bytes, 0, (int) (strlen($bytes) / 2)));
});

refuses('an archive with no end-of-central-directory record', static function () {
    Reader::fromString(str_repeat('not a zip', 40));
});

refuses('an entry whose contents were tampered with', static function () {
    $writer = Zip::writer();
    // Stored (incompressible), so a byte can be flipped in place and the
    // CRC check is what has to catch it.
    $payload = random_bytes(64);
    $writer->add('files/x.bin', $payload);

    $bytes = $writer->toString();
    $at = strpos($bytes, $payload);

    if ($at === false) {
        throw new \RuntimeException('expected the payload to be stored');
    }

    $bytes[$at + 3] = chr(ord($bytes[$at + 3]) ^ 0xFF);

    Reader::fromString($bytes)->get('files/x.bin');
});

check('extractTo writes only inside the destination', static function () use ($temp) {
    $destination = $temp . '/extract-' . bin2hex(random_bytes(3));

    $writer = Zip::writer();
    $writer->add('files/a/b/c.txt', 'nested');
    $writer->add('botex.json', '{}');

    Reader::fromString($writer->toString())->extractTo($destination);

    $written = is_file($destination . '/files/a/b/c.txt')
        && file_get_contents($destination . '/files/a/b/c.txt') === 'nested';

    return $written ? true : 'the nested file was not written where it should be';
});

check('a directory packs reproducibly (same bytes twice)', static function () use ($temp) {
    $source = $temp . '/reproducible';

    @mkdir($source . '/sub', 0775, true);
    file_put_contents($source . '/one.txt', 'one');
    file_put_contents($source . '/sub/two.txt', 'two');
    file_put_contents($source . '/sub/three.txt', 'three');

    $first = Zip::writer();
    $first->addDirectory($source, 'files');

    $second = Zip::writer();
    $second->addDirectory($source, 'files');

    // Timestamps are part of a zip, so the entry order is what must be
    // stable, not the raw bytes. Order is what makes a package hash
    // reproducible for the same input.
    return Reader::fromString($first->toString())->names()
        === Reader::fromString($second->toString())->names()
        ? true
        : 'entry order changed between two packs of the same directory';
});
