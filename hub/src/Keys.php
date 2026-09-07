<?php

namespace Hub;

use Botex\Archive\ArchiveException;

/**
 * Generates the signing key pair.
 *
 * Split out of the CLI because of one environment wrinkle worth handling
 * rather than pushing onto whoever runs the command: many Windows PHP
 * builds ship without an openssl.cnf, and openssl_pkey_new() then fails
 * with "configuration file routines::no such file" -- a message that says
 * nothing about what to do. Key generation does not actually need anything
 * out of that file, so a minimal one is written to a temp path and passed
 * in explicitly when the default attempt fails.
 */
class Keys
{
    /** RSA size. 4096 because a signing key is generated once and lives for years. */
    public const BITS = 4096;

    /**
     * Creates a key pair.
     *
     * @return array{private:string,public:string} PEM blocks
     *
     * @throws ArchiveException
     */
    public static function generate(int $bits = self::BITS): array
    {
        if (!extension_loaded('openssl')) {
            throw new ArchiveException('This PHP has no openssl, so keys cannot be generated.');
        }

        $settings = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Clear anything left by an earlier call, so the errors reported
        // below belong to this attempt.
        while (openssl_error_string() !== false) {
            // discard
        }

        $key = @openssl_pkey_new($settings);
        $temporary = null;

        if ($key === false) {
            $temporary = self::writeConfig();
            $key = @openssl_pkey_new($settings + ['config' => $temporary]);
        }

        try {
            if ($key === false) {
                throw new ArchiveException(
                    'Could not generate a key pair: ' . self::errors()
                        . '. On Windows this usually means PHP cannot find openssl.cnf; '
                        . 'set the OPENSSL_CONF environment variable to its location.'
                );
            }

            $private = '';

            $exported = $temporary === null
                ? openssl_pkey_export($key, $private)
                : openssl_pkey_export($key, $private, null, ['config' => $temporary]);

            if (!$exported || $private === '') {
                throw new ArchiveException('Could not export the private key: ' . self::errors());
            }

            $details = openssl_pkey_get_details($key);
            $public = (string) ($details['key'] ?? '');

            if ($public === '') {
                throw new ArchiveException('Could not read the public key.');
            }

            return ['private' => $private, 'public' => $public];
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Writes the pair to disk.
     *
     * @return array{private:string,public:string} file paths
     *
     * @throws ArchiveException
     */
    public static function write(string $directory, int $bits = self::BITS): array
    {
        // 0700: the private key must not be readable by the web server user,
        // which on shared hosting is often not the publishing user.
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ArchiveException("Could not create {$directory}");
        }

        $pair = self::generate($bits);

        $privateFile = rtrim($directory, '/\\') . '/botex-signing.key';
        $publicFile = rtrim($directory, '/\\') . '/botex-signing.pub';

        // Refuse to clobber: overwriting a signing key invalidates every
        // package already published with it, and there is no undo.
        foreach ([$privateFile, $publicFile] as $file) {
            if (is_file($file)) {
                throw new ArchiveException(
                    "{$file} already exists. Move it aside first -- replacing a signing key "
                        . 'means every bot trusting the old one will refuse your packages.'
                );
            }
        }

        if (@file_put_contents($privateFile, $pair['private']) === false) {
            throw new ArchiveException("Could not write {$privateFile}");
        }

        @chmod($privateFile, 0600);

        if (@file_put_contents($publicFile, $pair['public']) === false) {
            throw new ArchiveException("Could not write {$publicFile}");
        }

        return ['private' => $privateFile, 'public' => $publicFile];
    }

    /**
     * A minimal openssl.cnf, for builds that have none.
     *
     * Only the sections openssl insists on reading; key generation takes no
     * values from here.
     */
    private static function writeConfig(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'botex-openssl-');

        if ($file === false) {
            throw new ArchiveException('Could not create a temporary openssl config.');
        }

        file_put_contents($file, <<<'CNF'
            [ req ]
            default_bits = 4096
            distinguished_name = req_distinguished_name

            [ req_distinguished_name ]
            CN = botex
            CNF);

        return $file;
    }

    private static function errors(): string
    {
        $messages = [];

        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? 'no detail available' : implode('; ', array_unique($messages));
    }
}
