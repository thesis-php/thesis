<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * TLS settings for the client. The defaults verify the server against the system CA store.
 *
 * @api
 */
final readonly class ClientTls
{
    /**
     * @param ?non-empty-string $peerName the name the server certificate must be issued for, also sent as SNI.
     *     The host passed to {@see connect()} by default
     * @param bool $verifyPeer whether the certificate must be signed by a trusted CA. Without it TLS does not protect
     *     from an active attacker: prefer $caFile with a private CA, even in development
     * @param bool $verifyPeerName whether the certificate must be issued for $peerName
     * @param ?non-empty-string $caFile PEM file of the trusted CAs instead of the system store
     * @param ?non-empty-string $caPath directory of the trusted CAs (hashed names, see `openssl rehash`)
     * @param ?Certificate $certificate the client certificate for servers that require mutual TLS
     * @param list<non-empty-string> $alpnProtocols in the order of preference, e.g. ['h2', 'http/1.1']
     * @param ?non-empty-string $ciphers OpenSSL cipher list for TLS 1.2, the OpenSSL default if null
     */
    public function __construct(
        public public(set) ?string $peerName = null,
        public bool $verifyPeer = true,
        public bool $verifyPeerName = true,
        public ?string $caFile = null,
        public ?string $caPath = null,
        public ?Certificate $certificate = null,
        public array $alpnProtocols = [],
        public TlsVersion $minVersion = TlsVersion::Tls12,
        public ?string $ciphers = null,
    ) {}

    /**
     * @internal
     * @param ?non-empty-string $host the host the socket was connected to
     * @return array<non-empty-string, mixed>
     */
    public function contextOptions(?string $host): array
    {
        $peerName = $this->peerName ?? $host;

        if ($peerName === null && $this->verifyPeerName) {
            throw new \LogicException('The socket was not connected to a host name: pass ClientTls::$peerName.');
        }

        return array_filter(
            array: [
                'peer_name' => $peerName,
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeerName,
                'allow_self_signed' => false,
                'SNI_enabled' => $peerName !== null,
                'cafile' => $this->caFile,
                'capath' => $this->caPath,
                'alpn_protocols' => $this->alpnProtocols === [] ? null : implode(',', $this->alpnProtocols),
                'ciphers' => $this->ciphers,
                // The context of a connected socket is its own, so the captured certificate is this peer's.
                'capture_peer_cert' => true,
                ...$this->certificate->contextOptions ?? [],
            ],
            callback: static fn(mixed $value) => $value !== null,
        );
    }
}
