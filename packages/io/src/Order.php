<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
enum Order
{
    case Big;
    case Little;
    public const self Network = self::Big;

    public static function native(): self
    {
        /** @var ?self $order */
        static $order;

        return $order ??= pack('S', 1) === "\x01\x00" ? self::Little : self::Big;
    }
}
