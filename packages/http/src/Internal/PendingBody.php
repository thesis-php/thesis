<?php

declare(strict_types=1);

namespace Thesis\Http\Internal;

use Thesis\Http\Body;
use Thesis\Http\StringBody;

/**
 * @internal
 */
final class PendingBody
{
    private ?Body $body;

    public function __construct(string|Body $body)
    {
        $this->body = \is_string($body) ? new StringBody($body) : $body;
    }

    public function take(): Body
    {
        $body = $this->body ?? throw new \LogicException('The message body has already been taken.');
        $this->body = null;

        return $body;
    }
}
