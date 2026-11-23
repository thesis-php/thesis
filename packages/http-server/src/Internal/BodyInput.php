<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Status;
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
    public function __construct(
        private Reader $input,
        private Duration $idleTimeout,
    ) {}

    public function read(int $max): string
    {
        return timeoutOr($this->idleTimeout, fn() => $this->input->read($max))
            ?? throw new MalformedMessage('The request body did not arrive in time.', Status::REQUEST_TIMEOUT);
    }
}
