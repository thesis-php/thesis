<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Thesis\Http\Body;
use Thesis\Http\StringBody;
use const Thesis\Http\EMPTY_BODY;

/**
 * @internal
 */
final class PendingBody
{
    public function __construct(
        private null|string|Body $body,
    ) {}

    public function take(): Body
    {
        $body = $this->body ?? throw new \LogicException('The message body has already been taken.');
        $this->body = null;

        if ($body === '') {
            return EMPTY_BODY;
        }

        return \is_string($body) ? new StringBody($body) : $body;
    }

    /**
     * @param int<1, 2147483647> $depth
     * @param non-negative-int $limit maximum payload bytes to collect
     * @throws \LogicException if the body has already been taken or partially read
     * @throws \OverflowException if the payload exceeds the limit
     * @throws \JsonException if decoding fails
     */
    public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = 0,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        $body = $this->take();
        $bytes = $body->readAll($limit);
        $body->close();

        return json_decode(json: $bytes, associative: $associative, depth: $depth, flags: $flags | JSON_THROW_ON_ERROR);
    }

    public function __destruct()
    {
        if ($this->body instanceof Body) {
            $this->body->close();
        }
    }
}
