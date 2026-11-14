<?php

declare(strict_types=1);

namespace Thesis\Io;

final readonly class Hex
{
    public static function bytes(string $hex): string
    {
        $bytes = hex2bin($hex);
        \assert(\is_string($bytes));

        return $bytes;
    }

    private function __construct() {}
}
