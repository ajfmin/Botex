<?php

namespace Botex\Remote;

use Botex\Support\Config;

/**
 * Optional package signing, verified with an RSA public key the operator
 * pastes into config.
 *
 * Why bother, when the download already runs over TLS and its sha256 is
 * checked? Because TLS only proves you reached the host you asked for, and
 * the hash only proves the bytes match what the *same host* told you to
 * expect. Neither survives the archive itself being compromised: whoever
 * controls it can serve a new package and a matching hash. A signature
 * moves the trust from the server to a key that never has to live on it.
 *
 * Off by default -- an empty archive.public_key skips verification -- so a
 * private archive on a host you control is not forced through key
 * management it does not need. Set the key and it becomes mandatory: from
 * then on an unsigned package is refused rather than quietly accepted,
 * which is the only way a configured key is worth anything.
 */
class Signature
{
    private string $publicKey;

    public function __construct(Config $config)
    {
        $this->publicKey = $this->resolve(trim((string) $config->get('archive.public_key', '')));
    }

    /**
     * Accepts either a PEM block pasted into config or a path to one.
     *
     * Both are natural things to write in a config file, and guessing
     * between them is unambiguous: a key always contains its BEGIN line,
     * and a path never does.
     */
    private function resolve(string $value): string
    {
        if ($value === '' || str_contains($value, 'BEGIN')) {
            return $value;
        }

        if (!is_file($value) || !is_readable($value)) {
            throw new RemoteException(
                "archive.public_key points at '{$value}', which is not a readable file. "
                    . 'Give a path to a PEM public key, or paste the key itself.'
            );
        }

        return trim((string) file_get_contents($value));
    }

    /** Whether a key is configured, making signatures mandatory. */
    public function required(): bool
    {
        return $this->publicKey !== '';
    }

    /**
     * Verifies a detached signature over the package bytes.
     *
     * @param  string $bytes     the raw .botex file
     * @param  string $signature base64 of the RSA-SHA256 signature, '' if none
     *
     * @throws RemoteException when a key is configured and the check fails
     */
    public function verify(string $bytes, string $signature): void
    {
        if (!$this->required()) {
            return;
        }

        if (!extension_loaded('openssl')) {
            throw new RemoteException(
                'archive.public_key is set but this PHP has no openssl, '
                    . 'so signatures cannot be checked. Remove the key or enable openssl.'
            );
        }

        if (trim($signature) === '') {
            throw new RemoteException(
                'This package is unsigned, but archive.public_key requires a signature.'
            );
        }

        $decoded = base64_decode(trim($signature), true);

        if ($decoded === false) {
            throw new RemoteException('The package signature is not valid base64.');
        }

        $key = openssl_pkey_get_public($this->publicKey);

        if ($key === false) {
            throw new RemoteException(
                'archive.public_key is not a readable public key: ' . $this->opensslError()
            );
        }

        $result = openssl_verify($bytes, $decoded, $key, OPENSSL_ALGO_SHA256);

        if ($result === 1) {
            return;
        }

        if ($result === 0) {
            throw new RemoteException(
                'The package signature does not match. Refusing to install it.'
            );
        }

        throw new RemoteException('Could not check the package signature: ' . $this->opensslError());
    }

    /** Fingerprint of the configured key, for showing which key is trusted. */
    public function fingerprint(): string
    {
        if (!$this->required()) {
            return '';
        }

        return substr(hash('sha256', $this->publicKey), 0, 16);
    }

    private function opensslError(): string
    {
        $messages = [];

        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? 'unknown error' : implode('; ', $messages);
    }
}
