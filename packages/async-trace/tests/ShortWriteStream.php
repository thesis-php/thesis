<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

/**
 * A stream that takes at most $length bytes of a write, like a full pipe or socket: fwrite() returns fewer bytes
 * than given. PHP itself writes the rest to a stream wrapper until a write takes nothing,
 * so after a short write the next one takes nothing, and fwrite() returns what is written so far.
 * With $length 0 it takes nothing at all.
 */
final class ShortWriteStream
{
    private const string PROTOCOL = 'short-write';

    /**
     * Everything written to the last stream opened.
     */
    public static string $bytes = '';

    private static int $length = 0;

    /**
     * Set by PHP for a stream wrapper.
     */
    public mixed $context = null;

    private bool $full = false;

    /**
     * @param non-negative-int $length
     * @return resource
     */
    public static function open(int $length): mixed
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        self::$bytes = '';
        self::$length = $length;
        $stream = fopen(self::PROTOCOL . '://', 'w');

        if ($stream === false) {
            throw new \LogicException('Cannot open a short-write stream.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->full) {
            $this->full = false;

            return 0;
        }

        $taken = substr($data, 0, self::$length);
        self::$bytes .= $taken;
        $this->full = \strlen($taken) < \strlen($data);

        return \strlen($taken);
    }
}
