<?php

declare(strict_types=1);

namespace Thesis\Io;

final class RecordingWriter implements Writer
{
    /**
     * @var list<string>
     */
    public private(set) array $writes = [];

    public function write(string $bytes): void
    {
        $this->writes[] = $bytes;
    }
}
