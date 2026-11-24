<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\AbstractBody;
use Thesis\Http\Headers;
use Thesis\HttpProtocol\Http1\ChunkedReader;
use Thesis\HttpProtocol\Http1\FixedLengthReader;
use Thesis\Io\IoException;
use Thesis\Io\StreamClosed;
use Thesis\Net\Socket;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * @internal
 */
final class ResponseBody extends AbstractBody
{
    #[\Override]
    public ?int $size {
        get => $this->reader->size;
    }

    #[\Override]
    public bool $isBuffered {
        get => $this->reader->isBuffered;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->reader->mayHaveTrailers;
    }

    private bool $isClosed = false;

    private ?IoException $failure = null;

    /**
     * @param \Closure(Socket, bool): void $release called once with whether the message boundary was reached
     */
    public function __construct(
        private readonly FixedLengthReader|ChunkedReader|UntilCloseReader $reader,
        private readonly Socket $socket,
        private ?\Closure $release,
    ) {
        if ($reader instanceof FixedLengthReader && $reader->remaining === 0) {
            $this->finish(complete: true);
        }
    }

    private function __clone() {}

    public function close(): void
    {
        $this->isClosed = true;
        $this->finish(complete: false);
    }

    public function read(int $max): ?string
    {
        if ($this->isClosed) {
            throw new StreamClosed('The response body is closed.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->release === null) {
            return null;
        }

        try {
            $bytes = $this->reader->read($max);
        } catch (IoException $failure) {
            $this->failure = $failure;
            $this->finish(complete: false);

            throw $failure;
        }

        if ($bytes === null || ($this->reader instanceof FixedLengthReader && $this->reader->remaining === 0)) {
            $this->finish(complete: true);
        }

        return $bytes;
    }

    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return $this->reader instanceof ChunkedReader ? $this->reader->discard() : EMPTY_HEADERS;
    }

    private function finish(bool $complete): void
    {
        $release = $this->release;
        $this->release = null;
        if ($release !== null) {
            $release($this->socket, $complete);
        }
    }

    public function __destruct()
    {
        $this->finish(complete: false);
    }
}
