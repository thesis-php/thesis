<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Thesis\Monorepo\FixerSkip;

/**
 * What the configs of PHP CS Fixer, Rector and composer-dependency-analyser take from the manifests,
 * with absolute paths.
 *
 * @internal
 */
final readonly class ToolSkips
{
    /**
     * @throws MonorepoException a skipped path does not exist
     */
    public static function of(Monorepo $monorepo): self
    {
        $optional = [];
        $fixer = [];
        $rector = [];
        $errors = [];

        foreach ($monorepo->packages as $package) {
            $path = $monorepo->root . '/' . $package->path();

            if ($package->manifest->optional !== []) {
                $optional[$path . '/src'] = $package->manifest->optional;
            }

            foreach ($package->manifest->skip as $skip) {
                foreach ($skip->paths as $file) {
                    if (!file_exists($path . '/' . $file)) {
                        $errors[] = \sprintf(
                            '%s/%s: the skipped path %s does not exist, fix or remove the skip.',
                            $package->path(),
                            Monorepo::MANIFEST,
                            $file,
                        );
                    }

                    if ($skip instanceof FixerSkip) {
                        $fixer[] = $path . '/' . $file;
                    } else {
                        $rector[$skip->rule][] = $path . '/' . $file;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new MonorepoException(implode("\n", $errors));
        }

        return new self(
            optional: $optional,
            fixer: $fixer,
            rector: $rector,
        );
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $optional src/ of a package => its optional dependencies
     * @param list<non-empty-string> $fixer files that PHP CS Fixer does not touch
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $rector a rule => the paths it skips
     */
    private function __construct(
        public array $optional,
        public array $fixer,
        public array $rector,
    ) {}
}
