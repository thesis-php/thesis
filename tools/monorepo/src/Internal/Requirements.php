<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * External dependencies of the root composer.json. Packages may constrain one dependency differently,
 * the root takes the constraint that lies within all the others: one vendor serves every package,
 * so a looser constraint would let a package be tested with a version it does not allow.
 *
 * @internal
 */
final class Requirements
{
    /**
     * @var array<non-empty-string, non-empty-string>
     */
    public private(set) array $require = [];

    /**
     * @var array<non-empty-string, non-empty-string>
     */
    public private(set) array $requireDev = [];

    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * Not keyed by constraint: an integer-like constraint ("1") would become an integer key.
     *
     * @var array<non-empty-string, non-empty-list<array{constraint: non-empty-string, where: string}>>
     */
    private array $usages = [];

    /**
     * @var array<non-empty-string, true> dependencies required outside of require-dev
     */
    private array $runtime = [];

    /**
     * @throws MonorepoException with every constraint that cannot be reconciled, one per line
     */
    public static function resolve(Monorepo $monorepo): self
    {
        $requirements = new self();
        $config = $monorepo->config;
        $require = ['php' => $config->php, ...$config->require];
        $requirements->add(Monorepo::CONFIG, $require, runtime: true);
        $requirements->add(Monorepo::CONFIG, $config->requireDev, runtime: false);

        foreach ($monorepo->packages as $package) {
            $external = static fn(string $name) => $monorepo->package($name) === null;
            $require = array_filter($package->manifest->require, $external, ARRAY_FILTER_USE_KEY);
            $requireDev = array_filter($package->manifest->requireDev, $external, ARRAY_FILTER_USE_KEY);
            $requirements->add($package->path, $require, runtime: true);
            $requirements->add($package->path, $requireDev, runtime: false);
        }

        foreach ($requirements->usages as $name => $usages) {
            $constraint = $requirements->narrowest($name, $usages);

            if (isset($requirements->runtime[$name])) {
                $requirements->require[$name] = $constraint;
            } else {
                $requirements->requireDev[$name] = $constraint;
            }
        }

        if ($requirements->errors !== []) {
            throw new MonorepoException(implode("\n", $requirements->errors));
        }

        return $requirements;
    }

    private function __construct() {}

    /**
     * @param array<non-empty-string, non-empty-string> $require
     */
    private function add(string $where, array $require, bool $runtime): void
    {
        foreach ($require as $name => $constraint) {
            $this->usages[$name][] = ['constraint' => $constraint, 'where' => $where];

            if ($runtime) {
                $this->runtime[$name] = true;
            }
        }
    }

    /**
     * Reports an error and returns the first constraint, so that one run lists every conflict.
     *
     * @param non-empty-string $name
     * @param non-empty-list<array{constraint: non-empty-string, where: string}> $usages
     * @return non-empty-string
     */
    private function narrowest(string $name, array $usages): string
    {
        $parser = new VersionParser();
        $parsed = [];

        foreach ($usages as $usage) {
            try {
                $parsed[] = [
                    'constraint' => $usage['constraint'],
                    'parsed' => $parser->parseConstraints($usage['constraint']),
                ];
            } catch (\UnexpectedValueException $exception) {
                $this->errors[] = \sprintf(
                    '%s: %s requires %s, %s.',
                    $name,
                    $usage['where'],
                    $usage['constraint'],
                    lcfirst(rtrim($exception->getMessage(), '.')),
                );
            }
        }

        if (\count($parsed) !== \count($usages)) {
            return $usages[0]['constraint'];
        }

        foreach ($parsed as $candidate) {
            foreach ($parsed as $other) {
                if (!Intervals::isSubsetOf($candidate['parsed'], $other['parsed'])) {
                    continue 2;
                }
            }

            return $candidate['constraint'];
        }

        $this->errors[] = \sprintf(
            self::intersect(array_column($parsed, 'parsed'))
                ? 'Constraints of %s intersect, but none lies within all the others, narrow one to the intersection: %s.'
                : 'No version of %s satisfies every constraint: %s.',
            $name,
            self::describe($usages),
        );

        return $usages[0]['constraint'];
    }

    /**
     * @param list<ConstraintInterface> $constraints
     */
    private static function intersect(array $constraints): bool
    {
        $intervals = Intervals::get(MultiConstraint::create($constraints, conjunctive: true));

        return $intervals['numeric'] !== []
            || $intervals['branches']['names'] !== []
            || $intervals['branches']['exclude'];
    }

    /**
     * @param list<array{constraint: non-empty-string, where: string}> $usages
     */
    private static function describe(array $usages): string
    {
        $where = [];

        foreach ($usages as $usage) {
            $where[$usage['constraint']][] = $usage['where'];
        }

        $found = [];

        foreach ($where as $constraint => $places) {
            $found[] = \sprintf('%s in %s', $constraint, implode(', ', $places));
        }

        return implode('; ', $found);
    }
}
