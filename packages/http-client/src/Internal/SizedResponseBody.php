<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\Http1\FixedLengthReader;
use Thesis\Io\Closer;
use Thesis\Io\SizedReader;
use Thesis\Net\Socket;

/**
 * @internal
 */
final readonly class SizedResponseBody implements SizedReader, Closer
{
    public int $size;

    private ResponseBody $body;

    public function __construct(FixedLengthReader $reader, Socket $socket)
    {
        $this->body = new ResponseBody($reader, $socket);
        $this->size = $reader->size;
    }

    public function close(): void
    {
        $this->body->close();
    }

    public function read(int $max): string
    {
        return $this->body->read($max);
    }
}
