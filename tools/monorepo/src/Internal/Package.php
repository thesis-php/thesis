<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Thesis\Monorepo\Manifest;

/**
 * @internal
 */
final class Package
{
    /**
     * @var non-empty-string
     */
    public string $name {
        get => Monorepo::VENDOR . '/' . $this->dir;
    }

    /**
     * @var non-empty-string
     */
    public string $path {
        get => Monorepo::PACKAGES_DIR . '/' . $this->dir;
    }

    /**
     * @var non-empty-string
     */
    public string $title {
        get => $this->manifest->title ?? 'Thesis ' . ucwords(str_replace('-', ' ', $this->dir));
    }

    /**
     * @var non-empty-string the psr-4 prefix of src/ and tests/
     */
    public string $namespace {
        get {
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

    /**
     * The job infect of check.yaml runs on the package and sends its mutation score for the badge of README.md.
     */
    public bool $isMutationTested {
        get => $this->hasTests && $this->manifest->mutationTesting;
    }

    /**
     * @param non-empty-string $dir
     * @param list<non-empty-string> $files autoload files, relative to the package: those of src/ without classes
     *     (EagerFiles), then those of the manifest
     * @param list<non-empty-string> $devFiles autoload-dev files relative to the package. Includes eager files
     *     detected in tests/ by EagerFiles, followed by the manifest's devFiles.
     */
    public function __construct(
        public readonly string $dir,
        public readonly Manifest $manifest,
        public readonly array $files,
        public readonly array $devFiles,
        public readonly bool $hasSkills,
        public readonly bool $hasTests,
    ) {}
}
