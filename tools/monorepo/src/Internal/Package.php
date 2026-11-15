<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Thesis\Monorepo\Manifest;

/**
 * @internal
 */
final readonly class Package
{
    /**
     * @param non-empty-string $dir
     */
    public function __construct(
        public string $dir,
        public Manifest $manifest,
        public bool $hasSkills,
        public bool $hasTests,
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
        if ($this->manifest->namespace !== null) {
            return $this->manifest->namespace;
        }

        $pascalCase = str_replace(
            search: '-',
            replace: '',
            subject: ucwords($this->dir, '-'),
        );

        return Monorepo::NAMESPACE . $pascalCase . '\\';
    }
}
