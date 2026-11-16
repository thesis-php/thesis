<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Series;

/**
 * The tags and branches of a split repository as `git ls-remote` lists them. Versions are read as composer
 * reads them: v0.1.4 and 0.1.4 are one version, and an older package of the same name may have tags
 * in a format of its own.
 *
 * @internal
 */
final readonly class Refs
{
    private const string TAGS = 'refs/tags/';
    private const string BRANCHES = 'refs/heads/';

    /**
     * @param non-empty-string $repository for errors
     */
    public static function parse(string $lsRemote, string $repository): self
    {
        $parser = new VersionParser();
        $commits = [];

        foreach (explode("\n", trim($lsRemote)) as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([0-9a-f]{40})\t(\S+)$/D', $line, $match) !== 1) {
                throw new MonorepoException(\sprintf('git ls-remote %s: unexpected line "%s".', $repository, $line));
            }

            [, $id, $ref] = $match;

            // An annotated tag is listed twice, the second time peeled to its commit.
            $commits[str_ends_with($ref, '^{}') ? substr($ref, 0, -3) : $ref] = $id;
        }

        $tags = [];
        $branches = [];

        foreach ($commits as $ref => $commit) {
            if (str_starts_with($ref, self::BRANCHES)) {
                $branches[] = substr($ref, \strlen(self::BRANCHES));

                continue;
            }

            if (!str_starts_with($ref, self::TAGS)) {
                continue;
            }

            $name = substr($ref, \strlen(self::TAGS));

            try {
                $normalized = $parser->normalize($name);
            } catch (\UnexpectedValueException) {
                continue;
            }

            if ($name !== '' && !str_starts_with($normalized, 'dev-')) {
                $tags[] = ['name' => $name, 'commit' => $commit, 'normalized' => $normalized];
            }
        }

        return new self($tags, $branches);
    }

    /**
     * @param list<array{name: non-empty-string, commit: non-empty-string, normalized: string}> $tags
     *     that composer reads as versions
     * @param list<string> $branches
     */
    private function __construct(
        public array $tags,
        private array $branches,
    ) {}

    /**
     * @return list<array{name: non-empty-string, commit: non-empty-string, normalized: string}>
     */
    public function tagsOf(Version $version): array
    {
        $normalized = self::normalize($version);

        return array_values(array_filter($this->tags, static fn(array $tag) => $tag['normalized'] === $normalized));
    }

    /**
     * Every other tag of the series or above it: what the split did not write is an older package of the same name.
     *
     * @return list<array{name: non-empty-string, commit: non-empty-string, normalized: string}>
     */
    public function tagsFrom(Series $series, Version $except): array
    {
        $lowest = $series->major . '.' . $series->minor . '.0.0-dev';
        $normalized = self::normalize($except);

        return array_values(array_filter(
            $this->tags,
            static fn(array $tag) => $tag['normalized'] !== $normalized
                && Comparator::greaterThanOrEqualTo($tag['normalized'], $lowest),
        ));
    }

    /**
     * @return list<array{name: non-empty-string, commit: non-empty-string, normalized: string}>
     */
    public function tagsBelow(Series $series): array
    {
        $lowest = $series->major . '.' . $series->minor . '.0.0-dev';

        return array_values(array_filter(
            $this->tags,
            static fn(array $tag) => Comparator::lessThan($tag['normalized'], $lowest),
        ));
    }

    /**
     * The highest tag that satisfies the constraint, stable only if asked, null if there is none.
     *
     * @return ?array{name: non-empty-string, commit: non-empty-string, normalized: string}
     */
    public function highest(string $constraint, bool $stable, ?Version $except = null): ?array
    {
        $parser = new VersionParser();

        try {
            $allowed = $parser->parseConstraints($constraint);
        } catch (\UnexpectedValueException $exception) {
            throw new MonorepoException(
                message: \sprintf('%s is not a constraint: %s', $constraint, $exception->getMessage()),
                previous: $exception,
            );
        }
        $except = $except === null ? null : self::normalize($except);
        $highest = null;

        foreach ($this->tags as $tag) {
            if ($tag['normalized'] === $except
                || ($stable && VersionParser::parseStability($tag['normalized']) !== 'stable')
                || !$allowed->matches($parser->parseConstraints($tag['normalized']))
            ) {
                continue;
            }

            if ($highest === null || Comparator::greaterThan($tag['normalized'], $highest['normalized'])) {
                $highest = $tag;
            }
        }

        return $highest;
    }

    /**
     * A branch that composer would read as the series, 0.1.x or 0.1: it collides with the alias of main.
     */
    public function branchOf(Series $series): ?string
    {
        $parser = new VersionParser();
        $alias = $parser->normalizeBranch($series->toString() . '.x');

        foreach ($this->branches as $branch) {
            if ($parser->normalizeBranch($branch) === $alias) {
                return $branch;
            }
        }

        return null;
    }

    public static function normalize(Version $version): string
    {
        return new VersionParser()->normalize($version->toString());
    }
}
