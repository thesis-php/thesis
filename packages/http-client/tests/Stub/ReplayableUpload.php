<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Stub;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\ReplayableBody;
use Thesis\HttpClient\Internal\UntilCloseReader;
use Thesis\Io\Buffer;
use Thesis\Io\StringReader;

final class ReplayableUpload implements ReplayableBody
{
    #[\Override]
    public ?int $size {
        get => null;
    }

    #[\Override]
    public bool $isBuffered {
        get => false;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => false;
    }

    private readonly Body $reader;

    public function __construct(
        private readonly string $bytes,
    ) {
        $this->reader = new UntilCloseReader(new Buffer(), new StringReader($bytes));
    }

    public function fresh(): self
    {
        return new self($this->bytes);
    }

    public function read(int $max): ?string
    {
        return $this->reader->read($max);
    }

    public function readAll(): string
    {
        return $this->reader->readAll();
    }

    public function trailers(): Headers
    {
        return $this->reader->trailers();
    }

    public function close(): void
    {
        $this->reader->close();
    }
}
