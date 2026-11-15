<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * The lowest TLS version a side agrees to. Older ones are broken and not offered at all.
 *
 * @api
 */
enum TlsVersion
{
    case Tls12;
    case Tls13;

    /**
     * @internal
     */
    public function clientMethod(): int
    {
        return match ($this) {
            self::Tls12 => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            self::Tls13 => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        };
    }

    /**
     * @internal
     */
    public function serverMethod(): int
    {
        return match ($this) {
            self::Tls12 => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
            self::Tls13 => STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        };
    }
}
