<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1\Stub;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\StringBody;

final class StubUnknownLengthBody implements Body
{
    #[\Override]
    public ?int $size {
        get => null;
    }

    #[\Override]
    public bool $isBuffered {
        get => true;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->body->mayHaveTrailers;
    }

    private readonly StringBody $body;

    public function __construct(string $bytes)
    {
        $this->body = new StringBody($bytes);
    }

    public function read(int $max): ?string
    {
        return $this->body->read($max);
    }

    public function discard(): Headers
    {
        return $this->body->discard();
    }

    public function close(): void {}
}
