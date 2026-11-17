<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use Testo\Application\Config\SuiteConfig;
use Thesis\Monorepo\FixerSkip;
use Thesis\Monorepo\RectorSkip;

/**
 * What the configs of the tools take from the manifests, with absolute paths: the configs hold only the settings
 * of the tools.
 *
 * @internal
 */
final class Tools
{
    /**
     * @var list<non-empty-string> files that PHP CS Fixer does not touch
     */
    public array $fixerSkips {
        get {
            $skips = [];

            foreach ($this->skips() as [$skip, $path]) {
                if ($skip instanceof FixerSkip) {
                    $skips[] = $path;
                }
            }

            return $skips;
        }
    }

    /**
     * @var array<non-empty-string, non-empty-list<non-empty-string>> a rule => the paths it skips
     */
    public array $rectorSkips {
        get {
            $skips = [];

            foreach ($this->skips() as [$skip, $path]) {
                if ($skip instanceof RectorSkip) {
                    $skips[$skip->rule][] = $path;
                }
            }

            return $skips;
        }
    }

    /**
     * @var list<non-empty-string> src/ of every package
     */
    public array $testoSrc {
        get => array_map(
            fn(Package $package) => $this->monorepo->root . '/' . $package->path() . '/src',
            array_values($this->monorepo->packages),
        );
    }

    /**
     * @var non-empty-list<SuiteConfig> one per tests/ of a tool and of a package, named after its directory,
     *     with the plugins of the manifest
     */
    public array $testoSuites {
        get {
            // The tools have no manifests: a tool has a suite when it has tests.
            /** @var list<non-empty-string>|false $tools */
            $tools = glob($this->monorepo->root . '/tools/*/tests', GLOB_ONLYDIR);
            \assert($tools !== false);
            $suites = [];

            foreach ($tools as $tests) {
                $name = basename(\dirname($tests));
                \assert($name !== '');
                $suites[] = new SuiteConfig(name: $name, location: [$tests]);
            }

            foreach ($this->monorepo->packages as $package) {
                // Testo fails a suite without tests: packages/phpstan-extension has none, PHPStan checks it.
                if ($package->hasTests) {
                    $suites[] = new SuiteConfig(
                        name: $package->dir,
                        location: [$this->monorepo->root . '/' . $package->path() . '/tests'],
                        plugins: $package->manifest->testoPlugins,
                    );
                }
            }

            \assert($suites !== [], 'The monorepo has a package with tests.');

            return $suites;
        }
    }

    /**
     * @throws MonorepoException a skipped path does not exist
     */
    public static function of(Monorepo $monorepo): self
    {
        $tools = new self($monorepo);
        $errors = [];

        foreach ($tools->skips() as [, $path, $package, $file]) {
            if (!file_exists($path)) {
                $errors[] = \sprintf(
                    '%s/%s: the skipped path %s does not exist, fix or remove the skip.',
                    $package->path(),
                    Monorepo::MANIFEST,
                    $file,
                );
            }
        }

        if ($errors !== []) {
            throw new MonorepoException(implode("\n", $errors));
        }

        return $tools;
    }

    private function __construct(
        private readonly Monorepo $monorepo,
    ) {}

    /**
     * Allows the optional dependencies of a package in its src/.
     */
    public function ignoreOptionalDependencies(Configuration $config): Configuration
    {
        foreach ($this->monorepo->packages as $package) {
            $src = $this->monorepo->root . '/' . $package->path() . '/src';

            foreach ($package->manifest->optional as $dependency) {
                if (str_starts_with($dependency, 'ext-')) {
                    $config->ignoreErrorsOnExtensionAndPath($dependency, $src, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
                } else {
                    $config->ignoreErrorsOnPackageAndPath($dependency, $src, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
                }
            }
        }

        return $config;
    }

    /**
     * @return \Generator<array{FixerSkip|RectorSkip, non-empty-string, Package, non-empty-string}> a skip,
     *     the absolute path it skips, the package and the path relative to it
     */
    private function skips(): \Generator
    {
        foreach ($this->monorepo->packages as $package) {
            foreach ($package->manifest->skip as $skip) {
                foreach ($skip->paths as $file) {
                    yield [$skip, $this->monorepo->root . '/' . $package->path() . '/' . $file, $package, $file];
                }
            }
        }
    }
}
