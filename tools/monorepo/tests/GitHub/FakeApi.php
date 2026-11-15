<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\GitHub;

use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\MonorepoException;

final class FakeApi implements Api
{
    /**
     * @var list<non-empty-string> "METHOD path" of every write, in order
     */
    public array $writes = [];

    /**
     * @var array<non-empty-string, array<array-key, mixed>> "METHOD path" => body
     */
    public array $bodies = [];

    /**
     * @param array<string, array<array-key, mixed>> $resources path => what GET returns, a missing path is 404
     * @param array<string, string> $failing path => the message of the error that GET throws
     */
    public function __construct(
        private readonly array $resources = [],
        private readonly array $failing = [],
    ) {}

    #[\Override]
    public function get(string $path): ?array
    {
        if (isset($this->failing[$path])) {
            throw new MonorepoException($this->failing[$path]);
        }

        return $this->resources[$path] ?? null;
    }

    #[\Override]
    public function send(string $method, string $path, array $body): array
    {
        $write = $method . ' ' . $path;
        $this->writes[] = $write;
        $this->bodies[$write] = $body;

        return [];
    }
}
