<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * What the TLS handshake agreed on, in {@see Socket::$tls}.
 *
 * @api
 */
final readonly class TlsInfo
{
    /**
     * @internal
     * @param resource $stream after a successful handshake, before anything can suspend
     * @param bool $captured whether the handshake was sure to capture the peer certificate:
     *     otherwise the one in the context may be of another connection to the same server
     */
    public static function fromStream(mixed $stream, bool $captured): self
    {
        $crypto = stream_get_meta_data($stream)['crypto'] ?? [];
        $ssl = $captured ? stream_context_get_options($stream)['ssl'] ?? null : null;
        $peerCertificate = \is_array($ssl) ? $ssl['peer_certificate'] ?? null : null;

        return new self(
            protocol: $crypto['protocol'] ?? '',
            cipher: $crypto['cipher_name'] ?? '',
            alpnProtocol: $crypto['alpn_protocol'] ?? null,
            peerCertificate: $peerCertificate instanceof \OpenSSLCertificate ? $peerCertificate : null,
        );
    }

    /**
     * @param string $protocol `TLSv1.3`, `TLSv1.2`
     * @param string $cipher `TLS_AES_256_GCM_SHA384`
     * @param ?string $alpnProtocol null if the sides have not agreed on one
     * @param ?\OpenSSLCertificate $peerCertificate of the server on the client side; of the client on the server side
     *     if {@see ServerTls::$clientCaFile} requires one
     */
    public function __construct(
        public string $protocol,
        public string $cipher,
        public ?string $alpnProtocol,
        public ?\OpenSSLCertificate $peerCertificate,
    ) {}
}
