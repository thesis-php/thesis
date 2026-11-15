<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Writes nothing and throws, after calling $meanwhile: what other fibers do while a write waits.
 */
final readonly class FailingWriter implements Writer
{
    /**
     * @param \Closure(): void $meanwhile
     */
    public function __construct(
        private \Closure $meanwhile,
    ) {}

    public function write(string $bytes): void
    {
        ($this->meanwhile)();

        throw new WriteFailed('Nothing written.');
    }
}
