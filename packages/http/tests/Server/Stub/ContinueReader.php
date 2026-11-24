<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Testo\Assert;
use Thesis\Io\Reader;

final class ContinueReader implements Reader
{
    private int $reads = 0;

    public function __construct(
        private readonly RecordingWriter $output,
    ) {}

    public function read(int $max): ?string
    {
        if (++$this->reads === 1) {
            return "POST / HTTP/1.1\r\nHost: local\r\nExpect: 100-CoNtInUe\r\nContent-Length: 3\r\n\r\n";
        }

        if ($this->reads > 2) {
            return null;
        }

        Assert::true(str_starts_with($this->output->bytes, "HTTP/1.1 100 Continue\r\n\r\n"));

        return 'abc';
    }
}
