<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

/**
 * @api
 */
final readonly class MethodNotAllowed
{
    /**
     * @param non-empty-list<string> $allowedMethods
     */
    public function __construct(
        public array $allowedMethods,
    ) {}
}
