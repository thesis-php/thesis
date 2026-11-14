<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

/**
 * @internal
 */
final class Validator
{
    /**
     * Package name prefix => why it may not appear in a package's "require".
     */
    private const array FORBIDDEN_REQUIRE = [
        'amphp/' => 'the ecosystem is built on revolt/event-loop only (amphp is fine in requireDev for interop tests)',
    ];

    /**
     * @var array<string, string> namespace => dir, longest first
     */
    private readonly array $owners;

    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * @var array<string, true>
     */
    private array $visited = [];

    /**
     * @return list<string>
     */
    public static function validate(Monorepo $monorepo): array
    {
        $validator = new self($monorepo);
        $validator->validateMonorepo();

        foreach ($monorepo->packages as $package) {
            $validator->validatePackage($package);
        }

        return $validator->errors;
    }

    private function __construct(
        private readonly Monorepo $monorepo,
    ) {
        $owners = [];

        foreach ($monorepo->packages as $package) {
            $owners[$package->namespace()] = $package->dir;
        }

        uksort($owners, static fn(string $a, string $b) => \strlen($b) <=> \strlen($a));
        $this->owners = $owners;
    }

    private function validateMonorepo(): void
    {
        $this->errors = $this->monorepo->loadErrors;
        $this->checkAgentFiles('');
        $this->checkNamespaces();
        $this->checkConstraints();

        foreach (array_keys($this->monorepo->packages) as $dir) {
            $this->visit($dir, []);
        }
    }

    private function validatePackage(Package $package): void
    {
        $this->checkAgentFiles($package->path() . '/');
        $this->checkDependencies($package);
        $this->checkReferences(
            dir: $package->path() . '/src',
            section: 'uses',
            allowed: [$package->dir, ...$package->manifest->uses],
        );
        $this->checkReferences(
            dir: $package->path() . '/tests',
            section: 'uses or usesDev',
            allowed: [$package->dir, ...$package->manifest->uses, ...$package->manifest->usesDev],
        );
        $this->checkVisibility($package);
    }

    /**
     * AGENTS.md is the only instruction file: Claude Code reads AGENTS.md only while the project has no CLAUDE.md.
     */
    private function checkAgentFiles(string $prefix): void
    {
        if (!is_file($this->monorepo->root . '/' . $prefix . 'AGENTS.md')) {
            $this->errors[] = \sprintf('%sAGENTS.md is missing.', $prefix);
        }

        if (file_exists($this->monorepo->root . '/' . $prefix . 'CLAUDE.md')) {
            $this->errors[] = \sprintf(
                '%sCLAUDE.md must not exist: write AGENTS.md, a CLAUDE.md makes Claude Code ignore every AGENTS.md.',
                $prefix,
            );
        }
    }

    private function checkNamespaces(): void
    {
        $owners = [];

        foreach ($this->monorepo->packages as $package) {
            $namespace = $package->namespace();

            if (preg_match('/^Thesis(?:\\\[A-Z][A-Za-z0-9]*)+\\\$/D', $namespace) !== 1) {
                $this->errors[] = \sprintf(
                    '%s: namespace "%s" must be a sub-namespace of Thesis\ ending with a backslash.',
                    $package->path(),
                    $namespace,
                );
            }

            if (isset($owners[$namespace])) {
                $this->errors[] = \sprintf(
                    '%s: namespace "%s" is already owned by %s.',
                    $package->path(),
                    $namespace,
                    $owners[$namespace],
                );
            }

            $owners[$namespace] = $package->path();
        }
    }

    /**
     * One vendor directory serves every package, so an external dependency has one constraint across the monorepo.
     */
    private function checkConstraints(): void
    {
        $config = $this->monorepo->config;
        /** @var array<string, array<string, list<string>>> $usages dependency => constraint => where */
        $usages = [];

        foreach ([...$config->require, ...$config->requireDev] as $name => $constraint) {
            $usages[$name][$constraint][] = Monorepo::CONFIG;
        }

        foreach ($this->monorepo->packages as $package) {
            foreach ([...$package->manifest->require, ...$package->manifest->requireDev] as $name => $constraint) {
                $usages[$name][$constraint][] = $package->path();
            }
        }

        foreach ($usages as $name => $constraints) {
            if (\count($constraints) === 1) {
                continue;
            }

            $found = [];

            foreach ($constraints as $constraint => $where) {
                $found[] = \sprintf('%s in %s', $constraint, implode(', ', $where));
            }

            $this->errors[] = \sprintf(
                'Use one constraint for %s across the monorepo, found: %s.',
                $name,
                implode('; ', $found),
            );
        }
    }

    private function checkDependencies(Package $package): void
    {
        $manifest = $package->manifest;

        foreach ([...$manifest->require, ...$manifest->requireDev] as $name => $_) {
            if ($name === 'php') {
                $this->errors[] = \sprintf(
                    '%s: do not require php, its constraint comes from %s.',
                    $package->path(),
                    Monorepo::CONFIG,
                );
            }

            if (str_starts_with($name, Monorepo::VENDOR . '/')) {
                $this->errors[] = \sprintf(
                    '%s: requires %s, list monorepo packages in "uses" by directory name.',
                    $package->path(),
                    $name,
                );
            }
        }

        foreach ($manifest->require as $name => $_) {
            foreach (self::FORBIDDEN_REQUIRE as $prefix => $reason) {
                if (str_starts_with($name, $prefix)) {
                    $this->errors[] = \sprintf(
                        '%s: requires %s, but %s.',
                        $package->path(),
                        $name,
                        $reason,
                    );
                }
            }
        }

        foreach ([...$manifest->uses, ...$manifest->usesDev] as $dir) {
            $dependency = $this->monorepo->packages[$dir] ?? null;

            if ($dependency === null) {
                $this->errors[] = \sprintf(
                    '%s: uses "%s", which is not in %s/.',
                    $package->path(),
                    $dir,
                    Monorepo::PACKAGES_DIR,
                );

                continue;
            }

            if ($dependency === $package) {
                $this->errors[] = \sprintf('%s: uses itself.', $package->path());
            }

            $runtime = \in_array(
                needle: $dir,
                haystack: $manifest->uses,
                strict: true,
            );

            if ($runtime && !$manifest->layer->canDependOn($dependency->manifest->layer)) {
                $this->errors[] = \sprintf(
                    '%s (layer %s) uses %s (layer %s): a package may not depend on a higher layer.',
                    $package->path(),
                    $manifest->layer->value,
                    $dir,
                    $dependency->manifest->layer->value,
                );
            }
        }

        foreach (array_intersect($manifest->uses, $manifest->usesDev) as $dir) {
            $this->errors[] = \sprintf('%s: "%s" is in both uses and usesDev.', $package->path(), $dir);
        }
    }

    /**
     * The root autoloader loads every package, so nothing else would notice a name from a package missing in "uses".
     *
     * @param list<string> $allowed
     */
    private function checkReferences(string $dir, string $section, array $allowed): void
    {
        foreach (CodeScanner::phpFiles($this->monorepo->root . '/' . $dir) as $file) {
            foreach (CodeScanner::names($file, Monorepo::NAMESPACE) as $reference) {
                $owner = $this->ownerOf($reference->name);

                $isAllowed = $owner === null || \in_array(
                    needle: $owner,
                    haystack: $allowed,
                    strict: true,
                );

                if (!$isAllowed) {
                    $this->errors[] = \sprintf(
                        '%s:%d: uses %s from %s, which is not in "%s".',
                        $this->relative($file),
                        $reference->line,
                        $reference->name,
                        $owner,
                        $section,
                    );
                }
            }
        }
    }

    /**
     * Every symbol is either @api or @internal, and only internal symbols live in an Internal namespace,
     * so a user sees what is safe to depend on from the namespace alone.
     */
    private function checkVisibility(Package $package): void
    {
        foreach (CodeScanner::phpFiles($this->monorepo->root . '/' . $package->path() . '/src') as $file) {
            foreach (CodeScanner::declarations($file) as $declaration) {
                $name = $declaration->fullName();
                $internalNamespace = $declaration->inInternalNamespace();
                $problem = match (true) {
                    $declaration->api && $declaration->internal => "mark {$name} either @api or @internal, not both",
                    !$declaration->api && !$declaration->internal => "mark {$name} either @api or @internal",
                    $declaration->internal && !$internalNamespace => "{$name} is @internal, move it to an Internal namespace",
                    $declaration->api && $internalNamespace => "{$name} is in an Internal namespace, mark it @internal",
                    default => null,
                };

                if ($problem !== null) {
                    $this->errors[] = \sprintf('%s:%d: %s.', $this->relative($file), $declaration->line, $problem);
                }
            }
        }
    }

    /**
     * @param list<string> $stack
     */
    private function visit(string $dir, array $stack): void
    {
        $package = $this->monorepo->packages[$dir] ?? null;

        if ($package === null || isset($this->visited[$dir])) {
            return;
        }

        $position = array_search(
            needle: $dir,
            haystack: $stack,
            strict: true,
        );

        if (\is_int($position)) {
            $cycle = [...\array_slice($stack, $position), $dir];
            $this->errors[] = \sprintf('Dependency cycle: %s.', implode(' -> ', $cycle));

            return;
        }

        foreach ($package->manifest->uses as $dependency) {
            $this->visit($dependency, [...$stack, $dir]);
        }

        $this->visited[$dir] = true;
    }

    private function ownerOf(string $name): ?string
    {
        foreach ($this->owners as $namespace => $owner) {
            if (str_starts_with($name . '\\', $namespace)) {
                return $owner;
            }
        }

        return null;
    }

    private function relative(string $file): string
    {
        return substr($file, \strlen($this->monorepo->root) + 1);
    }
}
