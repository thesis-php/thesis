<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\GitHub;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * The REST API of GitHub, paths relative to https://api.github.com/.
 *
 * @internal
 */
interface Api
{
    /**
     * @param non-empty-string $path
     * @return ?array<array-key, mixed> null if there is no such resource
     * @throws MonorepoException
     */
    public function get(string $path): ?array;

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     * @throws MonorepoException
     */
    public function send(string $method, string $path, array $body): array;
}
