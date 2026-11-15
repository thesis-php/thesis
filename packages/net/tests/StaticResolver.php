<?php

declare(strict_types=1);

namespace Thesis\Net;

final readonly class StaticResolver implements Resolver
{
    /**
     * @param list<non-empty-string> $addresses
     */
    public function __construct(
        private array $addresses,
    ) {}

    public function resolve(string $host): array
    {
        if ($this->addresses === []) {
            throw new DnsFailed(\sprintf('Host "%s" not found.', $host));
        }

        return $this->addresses;
    }
}
