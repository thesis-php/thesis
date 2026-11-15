<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * A path of a Unix socket. The client end of a connection and both ends of {@see socketPair()} are unnamed:
 * their path is empty.
 *
 * @api
 */
final readonly class UnixAddress implements Address
{
    public function __construct(
        public string $path,
    ) {}

    /**
     * @return non-empty-string `unix:/run/app.sock`, `unix:` if unnamed
     */
    public function __toString(): string
    {
        return 'unix:' . $this->path;
    }
}
