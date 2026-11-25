<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

/**
 * @api
 */
final readonly class RouteMatch
{
    /**
     * @param array<non-empty-string, string> $params
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public array $params = [],
    ) {}

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }
}
