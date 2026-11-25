<?php

declare(strict_types=1);

namespace Thesis\Http\Internal;

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
        $body = $this->takePayload();

        if ($body === '') {
            return EMPTY_BODY;
        }

        return \is_string($body) ? new StringBody($body) : $body;
    }

    public function takePayload(): string|Body
    {
        $body = $this->body ?? throw new \LogicException('The message body has already been taken.');
        $this->body = null;

        return $body;
    }

    public function __destruct()
    {
        if ($this->body instanceof Body) {
            $this->body->close();
        }
    }
}
