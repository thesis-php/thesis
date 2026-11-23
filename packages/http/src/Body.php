<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Closer;
use Thesis\Io\Reader;
use Thesis\Io\SizedReader;
use Thesis\Io\StringReader;
use function Thesis\Io\close;
use const Thesis\Http\Internal\EMPTY_HEADERS;

/**
 * A message's byte stream and the trailer fields that follow it.
 *
 * @api
 */
final class Body implements Reader, Closer
{
    /**
     * Total length in bytes when known.
     *
     * @var ?non-negative-int
     */
    public ?int $size {
        get => ($this->reader instanceof SizedReader || $this->reader instanceof self) ? $this->reader->size : null;
    }

    /**
     * The payload can be read from memory without waiting for input.
     */
    public bool $isBuffered {
        get => $this->reader instanceof StringReader || ($this->reader instanceof self && $this->reader->isBuffered);
    }

    /**
     * Reading this property never waits. Null means the fields are still pending.
     */
    public ?Headers $trailers {
        get => $this->source instanceof \Closure ? ($this->source)() : $this->source;
    }

    private readonly Reader $reader;

    /**
     * @var Headers|(\Closure(): ?Headers)
     */
    private readonly Headers|\Closure $source;

    /**
     * @param Headers|(callable(): ?Headers) $trailers ready fields or a callback that observes them without I/O
     */
    public function __construct(string|Reader $reader, Headers|callable $trailers = EMPTY_HEADERS)
    {
        $this->reader = \is_string($reader) ? new StringReader($reader) : $reader;
        $this->source = $trailers instanceof Headers ? $trailers : $trailers(...);
    }

    private function __clone() {}

    public function read(int $max): ?string
    {
        return $this->reader->read($max);
    }

    /**
     * Reads and discards the remaining bytes, then returns the final trailer fields.
     */
    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return $this->trailers ?? throw new \LogicException('Trailers are unavailable after the body ended.');
    }

    /**
     * Releases a closable reader without draining it.
     */
    public function close(): void
    {
        close($this->reader);
    }
}
