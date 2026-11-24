<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Closer;
use Thesis\Io\Reader;

/**
 * A message's byte stream.
 *
 * @api
 */
interface Body extends Reader, Closer
{
    /**
     * Length of the payload in bytes, excluding trailers, or null if unknown.
     * Reading the body does not change this value.
     *
     * @var ?non-negative-int
     */
    public ?int $size { get; }

    /**
     * The payload can be read from memory without waiting for input.
     */
    public bool $isBuffered { get; }

    /**
     * An HTTP/1.1 sender chooses the framing before it writes the headers. If trailers are possible,
     * it must use chunked encoding even when the body size is known. For example, a body can compute
     * a digest as it streams and supply the digest as a trailer after the last byte.
     *
     * False guarantees empty trailers on successful completion. With true, the final fields may
     * still be empty. Read them with trailers() once the body has finished.
     */
    public bool $mayHaveTrailers { get; }

    /**
     * Returns the final fields without advancing the reader.
     *
     * @throws \LogicException if the fields are still pending
     */
    public function trailers(): Headers;

    /**
     * Reads the entire payload into a string.
     *
     * @throws \LogicException if any bytes have already been consumed
     */
    public function readAll(): string;
}
