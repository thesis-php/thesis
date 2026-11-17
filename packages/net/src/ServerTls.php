<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * TLS of the side that accepts.
 *
 * @api
 */
final class ServerTls
{
    /**
     * @internal
     * @var array<non-empty-string, mixed>
     */
    public array $contextOptions {
        get {
            $verifyClient = $this->clientCaFile !== null;

            return array_filter(
                array: [
                    ...$this->certificate->contextOptions,
                    'SNI_server_certs' => $this->sniCertificates === [] ? null : array_map(
                        callback: static fn(Certificate $certificate) => $certificate->contextOptions,
                        array: $this->sniCertificates,
                    ),
                    'alpn_protocols' => $this->alpnProtocols === [] ? null : implode(',', $this->alpnProtocols),
                    'ciphers' => $this->ciphers,
                    'verify_peer' => $verifyClient,
                    'verify_peer_name' => false,
                    'cafile' => $this->clientCaFile,
                    // Accepted sockets share the context of their server, and PHP writes the certificate there.
                    // Only a handshake that requires one is sure to write it, right before it succeeds.
                    'capture_peer_cert' => $verifyClient,
                ],
                callback: static fn(mixed $value) => $value !== null,
            );
        }
    }

    /**
     * @param Certificate $certificate presented to a client that asks for no name of $sniCertificates
     * @param array<non-empty-string, Certificate> $sniCertificates by host name (SNI). ALPN is not negotiated
     *     on a connection that gets one of them: PHP loses it when it switches the certificate, so such a client
     *     falls back to its default protocol (HTTP/1.1 for HTTP). Prefer one certificate for several names.
     * @param list<non-empty-string> $alpnProtocols in the order of preference, e.g. ['h2', 'http/1.1']
     * @param ?non-empty-string $clientCaFile if set, a client must present a certificate signed by these CAs
     *     (mutual TLS); it is then in {@see TlsInfo::$peerCertificate}
     * @param ?non-empty-string $ciphers OpenSSL cipher list for TLS 1.2, the OpenSSL default if null
     */
    public function __construct(
        public readonly Certificate $certificate,
        public readonly array $sniCertificates = [],
        public readonly array $alpnProtocols = [],
        public readonly ?string $clientCaFile = null,
        public readonly TlsVersion $minVersion = TlsVersion::Tls12,
        public readonly ?string $ciphers = null,
    ) {}
}
