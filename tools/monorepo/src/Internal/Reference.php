<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

/**
 * @internal
 */
final readonly class Reference
{
    public function __construct(
        public string $name,
        public int $line,
    ) {}
}
