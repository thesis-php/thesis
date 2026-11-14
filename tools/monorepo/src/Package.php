<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

final readonly class Package
{
    /**
     * @param non-empty-string $dir
     */
    public function __construct(
        public string $dir,
        public Manifest $manifest,
        public bool $hasSkills,
    ) {}

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return Monorepo::VENDOR . '/' . $this->dir;
    }

    /**
     * @return non-empty-string
     */
    public function path(): string
    {
        return Monorepo::PACKAGES_DIR . '/' . $this->dir;
    }

    /**
     * @return non-empty-string
     */
    public function namespace(): string
    {
        return $this->manifest->namespace ?? Monorepo::NAMESPACE . str_replace('-', '', ucwords($this->dir, '-')) . '\\';
    }
}
