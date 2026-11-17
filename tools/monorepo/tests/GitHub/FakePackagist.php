<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\GitHub;

use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Packagist\Packagist;

final class FakePackagist implements Packagist
{
    /**
     * @var list<non-empty-string> the URLs of the repositories registered, in order
     */
    public array $created = [];

    /**
     * @param list<string> $packages vendor/name of the registered ones
     * @param ?string $failing the message of the error that every call throws
     */
    public function __construct(
        private readonly array $packages = [],
        private readonly ?string $failing = null,
    ) {}

    #[\Override]
    public function exists(string $package): bool
    {
        $this->fail();

        return \in_array($package, $this->packages, strict: true);
    }

    #[\Override]
    public function create(string $repository): void
    {
        $this->fail();
        $this->created[] = $repository;
    }

    #[\Override]
    public function hook(): array
    {
        $this->fail();

        return ['url' => self::HOOK_URL . '?username=thesis', 'content_type' => 'json', 'secret' => 'token'];
    }

    private function fail(): void
    {
        if ($this->failing !== null) {
            throw new MonorepoException($this->failing);
        }
    }
}
