<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Stub;

use Thesis\Io\Writer;
use Time\Duration;
use function Thesis\Async\delay;

final class RecordingWriter implements Writer
{
    public string $bytes = '';

    public int $writes = 0;

    public function __construct(
        private readonly ?Duration $wait = null,
    ) {}

    public function write(string $bytes): void
    {
        if ($this->wait !== null) {
            delay($this->wait);
        }

        ++$this->writes;
        $this->bytes .= $bytes;
    }
}
