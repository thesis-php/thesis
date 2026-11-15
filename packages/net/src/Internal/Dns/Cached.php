<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

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
        public float $expiresAt,
    ) {}
}
