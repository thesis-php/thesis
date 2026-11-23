<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Thesis\Monorepo\Internal\GitHub\Repositories;

/**
 * The current series of a package, X.Y: what main of the package is (agents/versions.md).
 *
 * @internal
 */
final class Series
{
    private const string PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';

    public static function of(Package $package): self
    {
        return self::parse($package->manifest->version) ?? throw new MonorepoException(\sprintf(
            "%s/%s: the version %s is not a series X.Y, e.g. version: '0.1'.",
            $package->path,
            Monorepo::MANIFEST,
            $package->manifest->version,
        ));
    }

    public static function parse(string $series): ?self
    {
        if (preg_match(self::PATTERN, $series, $match) !== 1) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2]);
    }

    /**
     * The series of a generated composer.json, from the alias of main: a manifest of another commit cannot be run.
     *
     * @param array<array-key, mixed> $composer
     */
    public static function ofComposer(array $composer): ?self
    {
        $extra = $composer['extra'] ?? null;
        $aliases = \is_array($extra) ? $extra['branch-alias'] ?? null : null;
        $alias = \is_array($aliases) ? $aliases[self::branch()] ?? null : null;

        return \is_string($alias) && preg_match('/^(.+)\.x-dev$/D', $alias, $series) === 1
            ? self::parse($series[1])
            : null;
    }

    /**
     * @var non-empty-string the versions compatible with the series: below 1.0 a minor is a break, above it a major
     */
    public string $range {
        get => $this->major === 0 ? '^0.' . $this->minor : '^' . $this->major . '.0';
    }

    /**
     * @var non-empty-string
     */
    public string $alias {
        get => $this->major . '.' . $this->minor . '.x-dev';
    }

    /**
     * The branch that the alias names: main of every split repository.
     *
     * @return non-empty-string
     */
    public static function branch(): string
    {
        return 'dev-' . Repositories::DEFAULT_BRANCH;
    }

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
    ) {}

    /**
     * Whether every version the constraint allows is in the range of the series. The ranges of two series
     * are the same (2.0 and 2.1) or do not overlap, so a constraint is within one range at most.
     */
    public function allows(string $constraint): bool
    {
        $parser = new VersionParser();

        try {
            $allowed = $parser->parseConstraints($constraint);

            return Intervals::isSubsetOf($allowed, $parser->parseConstraints($this->range));
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    public function equals(self $other): bool
    {
        return $this->major === $other->major && $this->minor === $other->minor;
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor;
    }
}
