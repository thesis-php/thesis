<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Thesis\Monorepo\Internal\MonorepoException;

/**
 * The tags of a split repository as `git ls-remote` lists them. Versions are read as composer reads them:
 * v0.1.4 and 0.1.4 are one version, and an older package of the same name may have tags in a format of its own.
 *
 * @internal
 */
final readonly class Refs
{
    private const string TAGS = 'refs/tags/';

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

        foreach ($commits as $ref => $commit) {
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

        return new self($tags);
    }

    /**
     * @param list<array{name: non-empty-string, commit: non-empty-string, normalized: string}> $tags
     *     that composer reads as versions
     */
    private function __construct(
        public array $tags,
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
     * The highest tag that satisfies the constraint, stable only if asked, null if there is none.
     *
     * @return ?array{name: non-empty-string, commit: non-empty-string, normalized: string}
     */
    public function highest(string $constraint, bool $stable): ?array
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
        $highest = null;

        foreach ($this->tags as $tag) {
            if (($stable && VersionParser::parseStability($tag['normalized']) !== 'stable')
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

    public static function normalize(Version $version): string
    {
        return new VersionParser()->normalize($version->toString());
    }
}
