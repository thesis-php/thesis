<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Client;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\ChunkedReader;
use Thesis\Http\Internal\Http1\FixedLengthReader;
use Thesis\Http\StreamingBody;
use Thesis\Io\IoException;
use Thesis\Io\StreamClosed;
use Thesis\Net\Socket;

/**
 * @internal
 */
final class ResponseBody extends StreamingBody
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
    ) {}

    private function __clone() {}

    public function close(): void
    {
        $this->isClosed = true;
        $this->finish(complete: false);
    }

    protected function readPayload(int $max): ?string
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

    public function trailers(): Headers
    {
        return $this->reader->trailers();
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
