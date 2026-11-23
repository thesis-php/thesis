<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\Http1\FixedLengthReader;
use Thesis\Io\Closer;
use Thesis\Io\IoException;
use Thesis\Io\Reader;
use Thesis\Io\StreamClosed;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\Socket;

/**
 * @internal
 */
final class ResponseBody implements Reader, Closer
{
    private bool $isClosed = false;

    private ?IoException $failure = null;

    public function __construct(
        private readonly Reader $reader,
        private readonly Socket $socket,
    ) {}

    public function __destruct()
    {
        $this->socket->close();
    }

    public function close(): void
    {
        $this->isClosed = true;
        $this->socket->close();
    }

    public function read(int $max): string
    {
        if ($this->isClosed) {
            throw new StreamClosed('The response body is closed.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->socket->isClosed) {
            throw new UnexpectedEof();
        }

        try {
            $bytes = $this->reader->read($max);
        } catch (UnexpectedEof $eof) {
            $this->socket->close();

            throw $eof;
        } catch (IoException $failure) {
            $this->failure = $failure;
            $this->socket->close();

            throw $failure;
        }

        if ($this->reader instanceof FixedLengthReader && $this->reader->remaining === 0) {
            $this->socket->close();
        }

        return $bytes;
    }
}
