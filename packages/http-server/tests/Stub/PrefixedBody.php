<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Stub;

use Thesis\Http\AbstractBody;
use Thesis\Http\Body;
use Thesis\Http\Headers;

final class PrefixedBody extends AbstractBody
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

    public function read(int $max): ?string
    {
        if ($this->hasPrefix) {
            $this->hasPrefix = false;

            return '!';
        }

        return $this->body->read($max);
    }

    public function discard(): Headers
    {
        $this->hasPrefix = false;

        return $this->body->discard();
    }

    public function close(): void
    {
        $this->body->close();
    }
}
