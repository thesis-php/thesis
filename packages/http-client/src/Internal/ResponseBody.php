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

    /**
     * @param \Closure(Socket): void $release called once at the message boundary
     */
    public function __construct(
        private readonly Reader $reader,
        private readonly Socket $socket,
        private ?\Closure $release,
    ) {
        if ($reader instanceof FixedLengthReader && $reader->remaining === 0) {
            $this->finish();
        }
    }

    private function __clone() {}

    public function __destruct()
    {
        $this->abort();
    }

    public function close(): void
    {
        $this->isClosed = true;
        $this->abort();
    }

    public function read(int $max): string
    {
        if ($this->isClosed) {
            throw new StreamClosed('The response body is closed.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->release === null) {
            throw new UnexpectedEof();
        }

        try {
            $bytes = $this->reader->read($max);
        } catch (UnexpectedEof $eof) {
            $this->finish();

            throw $eof;
        } catch (IoException $failure) {
            $this->failure = $failure;
            $this->abort();

            throw $failure;
        }

        if ($this->reader instanceof FixedLengthReader && $this->reader->remaining === 0) {
            $this->finish();
        }

        return $bytes;
    }

    private function finish(): void
    {
        $release = $this->release;
        $this->release = null;
        if ($release !== null) {
            $release($this->socket);
        }
    }

    private function abort(): void
    {
        if ($this->release !== null) {
            $this->socket->close();
            $this->release = null;
        }
    }
}
