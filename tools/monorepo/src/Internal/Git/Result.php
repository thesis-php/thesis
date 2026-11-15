<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Git;

/**
 * @internal
 */
final readonly class Result
{
    public function __construct(
        public int $code,
        public string $stdout,
        public string $stderr,
    ) {}
}
