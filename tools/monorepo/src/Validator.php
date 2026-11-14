<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

final readonly class Validator
{
    /**
     * Package name prefix => why it may not appear in a package's "require".
     */
    private const array FORBIDDEN_REQUIRE = [
        'amphp/' => 'the ecosystem is built on revolt/event-loop only (amphp is fine in requireDev for interop tests)',
    ];

    /**
     * @return list<string>
     */
    public static function validate(Monorepo $monorepo): array
    {
        $errors = [
            ...$monorepo->errors,
            ...self::agentFiles($monorepo, '.'),
            ...self::namespaces($monorepo),
            ...self::constraints($monorepo),
            ...self::cycles($monorepo),
        ];

        foreach ($monorepo->packages as $package) {
            $errors = [
                ...$errors,
                ...self::agentFiles($monorepo, $package->path()),
                ...self::dependencies($monorepo, $package),
                ...self::code($monorepo, $package),
            ];
        }

        return $errors;
    }

    /**
     * AGENTS.md is the only instruction file: Claude Code reads AGENTS.md only while the project has no CLAUDE.md.
     *
     * @return list<string>
     */
    private static function agentFiles(Monorepo $monorepo, string $path): array
    {
        $errors = [];
        $prefix = $path === '.' ? '' : $path . '/';

        if (!is_file($monorepo->root . '/' . $prefix . 'AGENTS.md')) {
            $errors[] = \sprintf('%sAGENTS.md is missing.', $prefix);
        }

        if (file_exists($monorepo->root . '/' . $prefix . 'CLAUDE.md')) {
            $errors[] = \sprintf('%sCLAUDE.md must not exist: write AGENTS.md, a CLAUDE.md makes Claude Code ignore every AGENTS.md.', $prefix);
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function namespaces(Monorepo $monorepo): array
    {
        $errors = [];
        $owners = [];

        foreach ($monorepo->packages as $package) {
            $namespace = $package->namespace();

            if (preg_match('/^Thesis(?:\\\[A-Z][A-Za-z0-9]*)+\\\$/D', $namespace) !== 1) {
                $errors[] = \sprintf('%s: namespace "%s" must be a sub-namespace of Thesis\ ending with a backslash.', $package->path(), $namespace);
            }

            if (isset($owners[$namespace])) {
                $errors[] = \sprintf('%s: namespace "%s" is already owned by %s.', $package->path(), $namespace, $owners[$namespace]);
            }

            $owners[$namespace] = $package->path();
        }

        return $errors;
    }

    /**
     * One vendor directory serves every package, so an external dependency has one constraint across the monorepo.
     *
     * @return list<string>
     */
    private static function constraints(Monorepo $monorepo): array
    {
        /** @var array<string, array<string, list<string>>> $usages dependency => constraint => where */
        $usages = [];

        foreach ([...$monorepo->config->require, ...$monorepo->config->requireDev] as $name => $constraint) {
            $usages[$name][$constraint][] = Monorepo::CONFIG;
        }

        foreach ($monorepo->packages as $package) {
            foreach ([...$package->manifest->require, ...$package->manifest->requireDev] as $name => $constraint) {
                $usages[$name][$constraint][] = $package->path();
            }
        }

        $errors = [];

        foreach ($usages as $name => $constraints) {
            if (\count($constraints) > 1) {
                $found = [];

                foreach ($constraints as $constraint => $where) {
                    $found[] = \sprintf('%s in %s', $constraint, implode(', ', $where));
                }

                $errors[] = \sprintf('Use one constraint for %s across the monorepo, found: %s.', $name, implode('; ', $found));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function dependencies(Monorepo $monorepo, Package $package): array
    {
        $errors = [];
        $manifest = $package->manifest;

        foreach ([...$manifest->require, ...$manifest->requireDev] as $name => $_) {
            if ($name === 'php') {
                $errors[] = \sprintf('%s: do not require php, its constraint comes from %s.', $package->path(), Monorepo::CONFIG);
            }

            if (str_starts_with($name, Monorepo::VENDOR . '/')) {
                $errors[] = \sprintf('%s: requires %s, list monorepo packages in "uses" by directory name.', $package->path(), $name);
            }
        }

        foreach ($manifest->require as $name => $_) {
            foreach (self::FORBIDDEN_REQUIRE as $prefix => $reason) {
                if (str_starts_with($name, $prefix)) {
                    $errors[] = \sprintf('%s: requires %s, but %s.', $package->path(), $name, $reason);
                }
            }
        }

        foreach ([...$manifest->uses, ...$manifest->usesDev] as $dir) {
            $dependency = $monorepo->packages[$dir] ?? null;

            if ($dependency === null) {
                $errors[] = \sprintf('%s: uses "%s", which is not in %s/.', $package->path(), $dir, Monorepo::PACKAGES_DIR);

                continue;
            }

            if ($dependency === $package) {
                $errors[] = \sprintf('%s: uses itself.', $package->path());
            }

            if (\in_array($dir, $manifest->uses, true) && !$package->manifest->layer->canDependOn($dependency->manifest->layer)) {
                $errors[] = \sprintf(
                    '%s (layer %s) uses %s (layer %s): a package may not depend on a higher layer.',
                    $package->path(),
                    $manifest->layer->value,
                    $dir,
                    $dependency->manifest->layer->value,
                );
            }
        }

        foreach (array_intersect($manifest->uses, $manifest->usesDev) as $dir) {
            $errors[] = \sprintf('%s: "%s" is in both uses and usesDev.', $package->path(), $dir);
        }

        return $errors;
    }

    /**
     * The root autoloader loads every package, so nothing else would notice a name from a package missing in "uses".
     *
     * @return list<string>
     */
    private static function code(Monorepo $monorepo, Package $package): array
    {
        /** @var array<string, string> $owners namespace => dir, longest first */
        $owners = [];

        foreach ($monorepo->packages as $candidate) {
            $owners[$candidate->namespace()] = $candidate->dir;
        }

        uksort($owners, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $errors = [];
        $scopes = [
            'src' => ['uses', [$package->dir, ...$package->manifest->uses]],
            'tests' => ['uses or usesDev', [$package->dir, ...$package->manifest->uses, ...$package->manifest->usesDev]],
        ];

        foreach ($scopes as $dir => [$section, $allowed]) {
            foreach (CodeScanner::phpFiles($monorepo->root . '/' . $package->path() . '/' . $dir) as $file) {
                foreach (CodeScanner::names($file, Monorepo::NAMESPACE) as [$name, $line]) {
                    foreach ($owners as $namespace => $owner) {
                        if (!str_starts_with($name . '\\', $namespace)) {
                            continue;
                        }

                        if (!\in_array($owner, $allowed, true)) {
                            $errors[] = \sprintf(
                                '%s:%d: uses %s from %s, which is not in "%s".',
                                substr($file, \strlen($monorepo->root) + 1),
                                $line,
                                $name,
                                $owner,
                                $section,
                            );
                        }

                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function cycles(Monorepo $monorepo): array
    {
        $errors = [];
        $done = [];

        foreach (array_keys($monorepo->packages) as $dir) {
            self::visit($monorepo, $dir, [], $done, $errors);
        }

        return $errors;
    }

    /**
     * @param list<string> $stack
     * @param array<string, true> $done
     * @param list<string> $errors
     */
    private static function visit(Monorepo $monorepo, string $dir, array $stack, array &$done, array &$errors): void
    {
        $package = $monorepo->packages[$dir] ?? null;

        if ($package === null || isset($done[$dir])) {
            return;
        }

        $position = array_search($dir, $stack, true);

        if (\is_int($position)) {
            $errors[] = \sprintf('Dependency cycle: %s.', implode(' -> ', [...\array_slice($stack, $position), $dir]));

            return;
        }

        foreach ($package->manifest->uses as $dependency) {
            self::visit($monorepo, $dependency, [...$stack, $dir], $done, $errors);
        }

        $done[$dir] = true;
    }
}
