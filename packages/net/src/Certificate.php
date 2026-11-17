<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * A certificate with its private key, in PEM files.
 *
 * @api
 */
final readonly class Certificate
{
    /**
     * @param non-empty-string $certFile the certificate followed by its intermediate CAs, may contain the key too
     * @param ?non-empty-string $keyFile the private key, if it is not in $certFile
     * @param ?non-empty-string $passphrase of an encrypted private key
     */
    public function __construct(
        public string $certFile,
        public ?string $keyFile = null,
        #[\SensitiveParameter]
        public ?string $passphrase = null,
    ) {}

    /**
     * @internal
     * @return array<non-empty-string, non-empty-string>
     */
    public function contextOptions(): array
    {
        // A certificate of SNI_server_certs needs a key file, even if it is the certificate file.
        $options = ['local_cert' => $this->certFile, 'local_pk' => $this->keyFile ?? $this->certFile];

        if ($this->passphrase !== null) {
            $options['passphrase'] = $this->passphrase;
        }

        return $options;
    }
}
