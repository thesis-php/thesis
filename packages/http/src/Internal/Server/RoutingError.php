<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

/**
 * @internal
 */
final readonly class RoutingError
{
    /**
     * @param 400|404|405 $status
     * @param list<string> $allow
     */
    public function __construct(
        public int $status,
        public array $allow = [],
    ) {}
}
