<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Thesis\Http\Server\Internal\Http1\ChunkedReader;
use Thesis\Http\Server\Internal\Http1\FixedLengthReader;
use Thesis\Http\Server\Internal\Http1\MalformedMessage;
use Thesis\Http\Status;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use Time\Duration;
use function Thesis\Async\timeoutOr;

/**
 * Applies the idle timeout to reads from the transport, including reads of chunk framing and trailers.
 *
 * @internal
 */
final readonly class BodyInput implements Reader
{
    /**
     * @param non-negative-int $maxBodyBytes
     */
    public function __construct(
        private Buffer $buffer,
        private Reader $input,
        private Duration $idleTimeout,
        public int $maxBodyBytes,
    ) {}

    /**
     * @param ?non-negative-int $length null for chunked framing
     */
    public function decode(?int $length): null|ChunkedReader|FixedLengthReader
    {
        return match ($length) {
            null => new ChunkedReader($this->buffer, $this),
            0 => null,
            default => new FixedLengthReader($this->buffer, $this, $length),
        };
    }

    public function read(int $max): ?string
    {
        $bytes = timeoutOr($this->idleTimeout, fn() => $this->input->read($max), default: false);
        if ($bytes === false) {
            throw new MalformedMessage('The request body did not arrive in time.', Status::REQUEST_TIMEOUT);
        }

        return $bytes;
    }
}
