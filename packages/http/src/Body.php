<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Closer;
use Thesis\Io\Reader;

/**
 * A message's byte stream and the trailer fields that follow it.
 *
 * @api
 */
interface Body extends Reader, Closer
{
    /**
     * Total length in bytes when known.
     *
     * @var ?non-negative-int
     */
    public ?int $size { get; }

    /**
     * The payload can be read from memory without waiting for input.
     */
    public bool $isBuffered { get; }

    /**
     * The body can produce trailer fields. False guarantees that discard() will return empty headers.
     */
    public bool $mayHaveTrailers { get; }

    /**
     * Advances the read position to EOF and returns the final trailer fields, including after EOF was read.
     */
    public function discard(): Headers;
}
