<?php

declare(strict_types=1);

namespace Thesis\Logger\Stub;

use Thesis\Io\Writer;

final class RecordingWriter implements Writer
{
    /**
     * @var list<string>
     */
    public private(set) array $writes = [];

    #[\Override]
    public function write(string $bytes): void
    {
        $this->writes[] = $bytes;
    }
}
