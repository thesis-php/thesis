<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

use Thesis\Async\Instant;

/**
 * @internal
 */
final readonly class Cached
{
    /**
     * @param non-empty-list<non-empty-string> $addresses
     */
    public function __construct(
        public array $addresses,
        public Instant $expiresAt,
    ) {}
}
