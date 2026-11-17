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
     * @param list<non-empty-string> $files autoload files, relative to the package: those of src/ without classes
     *     (EagerFiles), then those of the manifest
     * @param list<non-empty-string> $devFiles the same, of tests/ and devFiles of the manifest
     */
    public function __construct(
        public string $dir,
        public Manifest $manifest,
        public array $files,
        public array $devFiles,
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
     * @return non-empty-string the psr-4 prefix of src/ and tests/
     */
    public function namespace(): string
    {
        if ($this->manifest->rootEntry) {
            return Monorepo::ROOT_NAMESPACE;
        }

        $pascalCase = str_replace(
            search: '-',
            replace: '',
            subject: ucwords($this->dir, '-'),
        );

        return Monorepo::ROOT_NAMESPACE . $pascalCase . '\\';
    }
}
