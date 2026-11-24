<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\StreamingBody;

final class PrefixedBody extends StreamingBody
{
    #[\Override]
    public ?int $size {
        get => null;
    }

    #[\Override]
    public bool $isBuffered {
        get => $this->body->isBuffered;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->body->mayHaveTrailers;
    }

    private bool $hasPrefix = true;

    public function __construct(
        private readonly Body $body,
    ) {}

    protected function readPayload(int $max): ?string
    {
        if ($this->hasPrefix) {
            $this->hasPrefix = false;

            return '!';
        }

        return $this->body->read($max);
    }

    public function trailers(): Headers
    {
        return $this->body->trailers();
    }

    public function close(): void
    {
        $this->body->close();
    }
}
