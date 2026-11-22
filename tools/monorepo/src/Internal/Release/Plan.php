<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Composer\Semver\Comparator;
use Thesis\Monorepo\Internal\Git\CatFile;
use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Series;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Internal\Split\Splitter;

/**
 * Decides what a push of main releases (agents/releases.md): the version right under [Unreleased] of every package
 * that has no tag in its split repository yet, or whose release was not finished. Everything is read from the tree
 * of the commit and from the tags of the split repositories, and everything is checked before anything is pushed:
 * one reason not to release a package releases nothing.
 *
 * @internal
 */
final class Plan
{
    private const string CHANGELOG = 'CHANGELOG.md';
    private const string COMPOSER = 'composer.json';

    /**
     * @var list<Release> dependencies first, none if there are errors
     */
    public private(set) array $releases = [];

    /**
     * @var list<string> every reason not to release
     */
    public private(set) array $errors = [];

    /**
     * @var array<string, string> package => the changes that its changelog lists under [Unreleased]
     */
    private array $unreleased = [];

    /**
     * @var array<string, non-empty-string> split commit => the commit of the monorepo it was made from
     */
    private array $origins = [];

    /**
     * @param non-empty-string $commit the resolved ID of a commit on main
     */
    public static function of(Git $git, Api $api, Remote $remote, string $commit): self
    {
        $plan = new self($git, $git->catFile(), $api, $remote, $commit);
        $headings = $plan->headings();

        if ($headings === []) {
            return $plan;
        }

        $splits = Splitter::split($git, $commit);
        $plan->origins = $splits->origins;
        $tags = $plan->tags(array_keys($headings));
        $releases = [];
        $requires = [];

        foreach ($headings as $package => ['version' => $version, 'notes' => $notes]) {
            // The tags of its split repository could not be listed, which is reported.
            if (!isset($tags[$package])) {
                continue;
            }

            try {
                $split = $splits->commits[$package] ?? throw new MonorepoException('no split.');
                $composer = $plan->composer($package);
                $release = $plan->release($package, $version, $notes, $tags[$package], $split, $composer);
            } catch (MonorepoException $exception) {
                $plan->refuse($package, $version, $exception->getMessage());

                continue;
            }

            if ($release !== null) {
                $releases[$package] = $release;
                $requires[$package] = self::dependencies($composer);
            }
        }

        if ($plan->errors === []) {
            $plan->releases = self::dependenciesFirst($releases, $requires);
        }

        return $plan;
    }

    /**
     * @param non-empty-string $commit
     */
    private function __construct(
        private readonly Git $git,
        private readonly CatFile $catFile,
        private readonly Api $api,
        private readonly Remote $remote,
        private readonly string $commit,
    ) {}

    /**
     * @return array<non-empty-string, array{version: Version, notes: non-empty-string}> package => the version
     *     right under [Unreleased] of its changelog at the commit, of those that have one
     */
    private function headings(): array
    {
        $headings = [];

        foreach (array_keys($this->catFile->subtrees($this->commit . ':' . Monorepo::PACKAGES_DIR)) as $package) {
            $path = self::path($package) . '/' . self::CHANGELOG;
            $content = $this->catFile->read($this->commit . ':' . $path, 'blob');

            if ($package === '' || $content === null) {
                continue;
            }

            try {
                $changelog = Changelog::parse($content, $path);
                $this->unreleased[$package] = $changelog->unreleased;

                if ($changelog->topVersion !== null) {
                    $headings[$package] = $changelog->top();
                }
            } catch (MonorepoException $exception) {
                $this->errors[] = $exception->getMessage();
            }
        }

        return $headings;
    }

    /**
     * @param non-empty-string $package
     * @param non-empty-string $notes
     * @param non-empty-string $split the split commit of the package
     * @param array<array-key, mixed> $composer
     * @return ?Release null if the version is released and repeated in the monorepo, an older package
     *     of the name has it, or it is refused
     */
    private function release(
        string $package,
        Version $version,
        string $notes,
        Refs $tags,
        string $split,
        array $composer,
    ): ?Release {
        $tagged = $tags->tagsOf($version);

        if ($tagged === []) {
            $reasons = $this->reasonsNotToRelease($package, $version, $tags, $composer);

            foreach ($reasons as $reason) {
                $this->refuse($package, $version, $reason);
            }

            return $reasons !== [] ? null : new Release(
                package: $package,
                version: $version,
                tag: $version->tag,
                commit: $split,
                origin: $this->origins[$split] ?? null,
                notes: $notes,
                tagged: false,
                latest: self::isLatest($version, $tags),
            );
        }

        // The release writes vX.Y.Z: a tag of the version without v is of an older package of the same name.
        $tag = array_find($tagged, static fn(array $tag) => $tag['name'] === $version->tag);

        if ($tag === null) {
            return null;
        }

        // An earlier run pushed the tag and failed before one of the GitHub Releases, or the monorepo
        // did not repeat the releases then.
        $release = new Release(
            package: $package,
            version: $version,
            tag: $tag['name'],
            commit: $tag['commit'],
            origin: $this->origins[$tag['commit']] ?? null,
            notes: $notes,
            tagged: true,
            latest: self::isLatest($version, $tags),
        );
        $repository = Repositories::ORGANIZATION . '/' . $package;
        $finished = Publisher::hasGitHubRelease($this->api, $repository, $release->tag)
            // Without the commit to tag the monorepo is left to the run of the commit of the tag.
            && ($release->origin === null
                || Publisher::hasGitHubRelease($this->api, Publisher::MONOREPO, $release->mirrorTag));

        return $finished ? null : $release;
    }

    /**
     * @param non-empty-string $package
     * @param array<array-key, mixed> $composer
     * @return list<string>
     */
    private function reasonsNotToRelease(string $package, Version $version, Refs $tags, array $composer): array
    {
        $reasons = [];
        $path = self::path($package);
        $series = Series::ofComposer($composer);

        if (($this->unreleased[$package] ?? '') !== '') {
            $reasons[] = \sprintf(
                '[Unreleased] of %s/%s lists changes that are not part of it.',
                $path,
                self::CHANGELOG,
            );
        }

        if ($series === null) {
            $reasons[] = \sprintf('%s/%s has no branch alias of main.', $path, self::COMPOSER);
        } elseif (!$version->isOf($series)) {
            $reasons[] = \sprintf('it is not of the series %s of %s.', $series->toString(), $path);
        } else {
            $reasons = [...$reasons, ...self::notNext($version, $series, $tags)];
        }

        // The tests ran against the heads of the dependencies, and a user installs their tags.
        foreach (self::dependencies($composer) as $dependency) {
            if (($this->unreleased[$dependency] ?? '') !== '') {
                $reasons[] = \sprintf(
                    'it requires %s/%s, whose [Unreleased] lists changes: release it too.',
                    Monorepo::VENDOR,
                    $dependency,
                );
            }
        }

        if (!Repositories::isSplit($this->api, $package)) {
            $reasons[] = \sprintf(
                '%s/%s is not a split: it is missing, has no rulesets of the split or redirects to a renamed one.',
                Repositories::ORGANIZATION,
                $package,
            );
        }

        return $reasons;
    }

    /**
     * A typo like 0.1.14 for 0.1.4 would publish a version for good.
     *
     * @return list<string>
     */
    private static function notNext(Version $version, Series $series, Refs $tags): array
    {
        $last = $tags->highest($series->toString() . '.*', stable: false);
        $lastVersion = $last === null ? null : Version::parse($last['name']);

        if ($last !== null && $lastVersion === null) {
            return [\sprintf('the last tag of the series, %s, is not a version the release writes.', $last['name'])];
        }

        $next = Version::next($series, $lastVersion);

        if (array_any($next, static fn(Version $candidate) => $candidate->equals($version))) {
            return [];
        }

        return [\sprintf(
            'it does not come right after %s, expected %s.',
            $last === null ? 'no version of the series ' . $series->toString() : $last['name'],
            implode(', ', array_map(static fn(Version $candidate) => $candidate->toString(), $next)),
        )];
    }

    /**
     * Whether it is the highest stable version of the package, the tags of an older package of the name included.
     */
    private static function isLatest(Version $version, Refs $tags): bool
    {
        $highest = $tags->highest('*', stable: true)['normalized'] ?? null;

        return !$version->preRelease
            && ($highest === null || Comparator::greaterThanOrEqualTo(Refs::normalize($version), $highest));
    }

    private function refuse(string $package, Version $version, string $reason): void
    {
        $this->errors[] = \sprintf('%s %s: %s', $package, $version->toString(), $reason);
    }

    /**
     * Lists the tags of the split repositories of the packages, all at once.
     *
     * @param list<non-empty-string> $packages
     * @return array<string, Refs> package => the tags of its split repository, of those that could be listed
     */
    private function tags(array $packages): array
    {
        $commands = [];

        foreach ($packages as $package) {
            $commands[$package] = ['ls-remote', $this->remote->url($package), 'refs/tags/*'];
        }

        $tags = [];

        foreach ($this->git->with($this->remote->env)->runAll($commands) as $package => $result) {
            $repository = Repositories::ORGANIZATION . '/' . $package;

            if ($result->code === 0) {
                $tags[$package] = Refs::parse($result->stdout, $repository);
            } else {
                $this->errors[] = \sprintf('%s: git ls-remote: %s', $repository, trim($result->stderr));
            }
        }

        return $tags;
    }

    /**
     * @param non-empty-string $package
     * @return array<array-key, mixed> its generated composer.json at the commit
     */
    private function composer(string $package): array
    {
        $path = self::path($package) . '/' . self::COMPOSER;
        $content = $this->catFile->read($this->commit . ':' . $path, 'blob')
            ?? throw new MonorepoException(\sprintf('no %s.', $path));

        return Json::decode($content, $path);
    }

    /**
     * @param array<array-key, mixed> $composer
     * @return list<non-empty-string> the directories of the packages of our vendor that it requires
     */
    private static function dependencies(array $composer): array
    {
        $require = $composer['require'] ?? null;
        $prefix = Monorepo::VENDOR . '/';
        $dependencies = [];

        foreach (array_keys(\is_array($require) ? $require : []) as $name) {
            $name = (string) $name;
            $package = str_starts_with($name, $prefix) ? substr($name, \strlen($prefix)) : '';

            if ($package !== '') {
                $dependencies[] = $package;
            }
        }

        return $dependencies;
    }

    /**
     * @return non-empty-string
     */
    private static function path(string $package): string
    {
        return Monorepo::PACKAGES_DIR . '/' . $package;
    }

    /**
     * @param array<non-empty-string, Release> $releases
     * @param array<non-empty-string, list<non-empty-string>> $requires package => the packages it requires
     * @return list<Release>
     */
    private static function dependenciesFirst(array $releases, array $requires): array
    {
        ksort($releases);
        $ordered = [];

        while ($releases !== []) {
            $waiting = array_keys($releases);
            $ready = array_filter(
                $releases,
                static fn(Release $release) => array_intersect($requires[$release->package] ?? [], $waiting) === [],
            );

            if ($ready === []) {
                throw new MonorepoException(\sprintf('%s require each other.', implode(', ', $waiting)));
            }

            $ordered = [...$ordered, ...array_values($ready)];
            $releases = array_diff_key($releases, $ready);
        }

        return $ordered;
    }
}
