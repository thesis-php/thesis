<?php

declare(strict_types=1);

namespace Thesis\Io;

use function Thesis\Async\checkpoint;

/**
 * A stream wrapper whose write yields after taking one byte.
 */
final class PausingWriteStream
{
    public mixed $context = null;

    public static string $bytes = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$bytes = '';

        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }

    public function stream_write(string $bytes): int
    {
        self::$bytes .= $bytes[0];
        checkpoint();
        self::$bytes .= substr($bytes, 1);

        return \strlen($bytes);
    }
}
