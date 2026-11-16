<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Thesis\Monorepo\Internal\Git\CatFile;
use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Series;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Internal\Split\Split;
use Thesis\Monorepo\Internal\Split\Splitter;

/**
 * Decides what a push of main releases (agents/releases.md): the version right under [Unreleased] of every package
 * that has no tag in its split repository yet. Everything is read from the tree of the commit and from the split
 * repositories, nothing from the working tree. Everything is checked before anything is pushed. A package that
 * is refused keeps back itself and the packages that require it, the others are released.
 *
 * @internal
 */
final class Plan
{
    private const string CHANGELOG = 'CHANGELOG.md';
    private const string COMPOSER = 'composer.json';
    private const string ORIGIN_MAIN = 'refs/remotes/origin/' . Repositories::DEFAULT_BRANCH;

    /**
     * @var list<Release> dependencies first
     */
    public private(set) array $releases = [];

    /**
     * @var array<string, list<string>> package => every reason not to release it
     */
    public private(set) array $refusals = [];

    /**
     * @var array<string, Refs> package => the refs of its split repository
     */
    private array $refs = [];

    /**
     * @var array<string, true> package => whether ls-remote of its split repository ran, failed or not
     */
    private array $listed = [];

    /**
     * @var array<string, non-empty-string> package => its split commit
     */
    private array $splits = [];

    /**
     * @var array<string, Version> package => the version without a tag that the push releases
     */
    private array $versions = [];

    /**
     * @var list<array<string, non-empty-string>> package => its split, of every head of main this clone knows
     */
    private array $mains = [];

    /**
     * @param non-empty-string $commit of main, resolved
     */
    public static function of(Git $git, Api $api, Remote $remote, string $commit): self
    {
        $plan = new self($git, $git->catFile(), $api, $remote, $commit);
        $changelogs = $plan->changelogs();

        if ($changelogs === []) {
            return $plan;
        }

        $plan->splits = Splitter::split($git, $commit);
        $plan->mains = $plan->splitMains();
        $composers = [];

        foreach (array_keys($changelogs) as $package) {
            try {
                $composers[$package] = $plan->composer($commit, $package);
            } catch (MonorepoException $exception) {
                $plan->refusals[$package][] = $exception->getMessage();
                unset($changelogs[$package]);
            }
        }

        $plan->fetchRefs(array_keys($changelogs));
        $releases = [];
        $pending = [];

        foreach ($changelogs as $package => $changelog) {
            try {
                $state = $plan->state($package, $changelog);
            } catch (MonorepoException $exception) {
                $plan->refusals[$package][] = \sprintf('%s: %s', $package, $exception->getMessage());

                continue;
            }

            if ($state instanceof Version) {
                $pending[$package] = $changelog;
                $plan->versions[$package] = $state;
            } elseif ($state !== null) {
                $releases[$package] = $state;
            }
        }

        $dependencies = [];

        foreach (array_keys($pending) as $package) {
            $dependencies = [...$dependencies, ...$plan->dependencies($composers[$package] ?? [])];
        }

        $plan->fetchRefs(array_values(array_unique($dependencies)));

        foreach ($pending as $package => $changelog) {
            try {
                $release = $plan->release($package, $changelog, $composers[$package] ?? []);
            } catch (MonorepoException $exception) {
                $plan->refusals[$package][] = \sprintf('%s: %s', $package, $exception->getMessage());

                continue;
            }

            if ($release !== null) {
                $releases[$package] = $release;
            }
        }

        $plan->refuseDependents($releases, $composers);
        $plan->releases = self::dependenciesFirst($releases);

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
     * @return array<non-empty-string, Changelog> package => its changelog at the commit, of those with a version
     */
    private function changelogs(): array
    {
        $tree = $this->catFile->subtrees($this->commit . ':' . Monorepo::PACKAGES_DIR);
        $packages = array_map(strval(...), array_keys($tree));
        $changelogs = [];

        foreach ($packages as $package) {
            if ($package === '') {
                continue;
            }

            $path = self::path($package) . '/' . self::CHANGELOG;
            $content = $this->catFile->read($this->commit . ':' . $path, 'blob');

            try {
                $changelog = $content === null ? null : Changelog::parse($content, $path);
            } catch (MonorepoException $exception) {
                $this->refusals[$package][] = $exception->getMessage();

                continue;
            }

            if ($changelog?->topVersion() !== null) {
                $changelogs[$package] = $changelog;
            }
        }

        return $changelogs;
    }

    /**
     * What to do with the version right under [Unreleased].
     *
     * @param non-empty-string $package
     * @return Version|Release|null a version without a tag; the GitHub Release of a tag that an earlier run pushed
     *     but did not release; null when it is released or refused, or an older package of the name has the version
     */
    private function state(string $package, Changelog $changelog): null|Version|Release
    {
        $version = Version::parse($changelog->topVersion() ?? '');
        $refs = $this->refs[$package] ?? null;

        if ($version === null) {
            // Refused with the reason why the heading is not of a release.
            $this->notes($package, $changelog);

            return null;
        }

        if ($refs === null) {
            return null;
        }

        $tags = $refs->tagsOf($version);

        if ($tags === []) {
            return $version;
        }

        $split = $this->splits[$package] ?? '';

        foreach ($tags as $tag) {
            if (!$this->isOurs($package, $tag['commit'], $split)) {
                continue;
            }

            $notes = $this->notes($package, $changelog);
            $tagNotes = $this->tagNotes($version, $tag['commit']);

            // A tag of a later split (a newer push released it) may have notes amended before its release.
            $since = $this->isAncestor($tag['commit'], $split);

            if ($since && $notes !== null && $tagNotes !== null && $notes !== $tagNotes) {
                $this->refusals[$package][] = \sprintf(
                    '%s %s: its notes changed since the tag %s: list the changes after it under [Unreleased].',
                    $package,
                    $version->toString(),
                    $tag['name'],
                );
            }

            $repository = Repositories::ORGANIZATION . '/' . $package;
            $existing = \sprintf('repos/%s/releases/tags/%s', $repository, rawurlencode($tag['name']));

            if ($this->api->get($existing) !== null) {
                return null;
            }

            $notes = $tagNotes ?? $notes;

            return $notes === null ? null : new Release(
                package: $package,
                version: $version,
                tag: $tag['name'],
                commit: $tag['commit'],
                notes: $notes,
                tagged: true,
                latest: self::isLatest($version, $refs),
                requires: [],
            );
        }

        return null;
    }

    /**
     * The notes of the version in the changelog of its tag, the changelog of the split: the GitHub Release
     * describes what the tag has, and a change listed under the heading after the tag is not in it.
     *
     * @return ?non-empty-string null if the tag has no changelog with the version on top (not written by the release)
     */
    private function tagNotes(Version $version, string $tag): ?string
    {
        $content = $this->catFile->read($tag . ':' . self::CHANGELOG, 'blob');

        try {
            $top = $content === null ? null : Changelog::parse($content, self::CHANGELOG)->top();
        } catch (MonorepoException) {
            return null;
        }

        return $top !== null && $top['version']->equals($version) ? $top['notes'] : null;
    }

    /**
     * @return ?non-empty-string the notes of the version under [Unreleased], null if the package is refused
     *     because it is not a heading of a release
     */
    private function notes(string $package, Changelog $changelog): ?string
    {
        try {
            return $changelog->top()['notes'];
        } catch (MonorepoException $exception) {
            $this->refusals[$package][] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @param non-empty-string $package
     * @param array<array-key, mixed> $composer
     */
    private function release(string $package, Changelog $changelog, array $composer): ?Release
    {
        $version = $this->versions[$package] ?? throw new MonorepoException(\sprintf('%s: no version.', $package));
        $notes = $this->notes($package, $changelog);
        $split = $this->splits[$package] ?? throw new MonorepoException(\sprintf('%s: no split.', $package));
        $refs = $this->refs[$package] ?? null;
        $series = Series::ofComposer($composer);
        $errors = [];

        if ($changelog->unreleased !== '') {
            $errors[] = \sprintf('[Unreleased] of %s lists changes that are not part of it.', $changelog->path);
        }

        $below = Version::parse($changelog->versionBelowTop() ?? '');

        // Only the top heading is released: one under it without a tag would never be.
        if ($below !== null && $refs !== null && $refs->tagsOf($below) === []) {
            $errors[] = \sprintf(
                '%s under it has no tag: release it first by a re-run of the job of its push, or merge it into %s.',
                $below->toString(),
                $version->toString(),
            );
        }

        if ($series === null) {
            $errors[] = \sprintf('%s/%s has no branch alias of main.', self::path($package), self::COMPOSER);
        } elseif (!$version->isOf($series)) {
            $errors[] = \sprintf('it is not of the series %s of %s.', $series->toString(), self::path($package));
        }

        if (!Repositories::isSplit($this->api, $package)) {
            $errors[] = \sprintf(
                '%s/%s is not a split: it is missing, has no rulesets of the split or redirects to a renamed one.',
                Repositories::ORGANIZATION,
                $package,
            );
        }

        if ($series !== null && $refs !== null) {
            $errors = [
                ...$errors,
                ...$this->foreignRefs($package, $version, $series, $refs, $split),
                ...self::notNext($version, $series, $refs),
                ...$this->changedRanges($version, $series, $refs, $composer),
            ];
        }

        $errors = [...$errors, ...$this->unreleasedDependencies($version, $composer)];

        foreach ($errors as $error) {
            $this->refusals[$package][] = \sprintf('%s %s: %s', $package, $version->toString(), $error);
        }

        if ($notes === null || isset($this->refusals[$package])) {
            return null;
        }

        return new Release(
            package: $package,
            version: $version,
            tag: $version->tag(),
            commit: $split,
            notes: $notes,
            tagged: false,
            latest: $refs === null || self::isLatest($version, $refs),
            requires: $this->dependencies($composer),
        );
    }

    private static function isLatest(Version $version, Refs $refs): bool
    {
        $highest = $refs->highest('*', stable: true, except: $version);

        return !$version->preRelease
            && ($highest === null || Comparator::greaterThan(Refs::normalize($version), $highest['normalized']));
    }

    /**
     * A package whose dependency is refused is refused too: it would require a version that is not there.
     *
     * @param array<non-empty-string, Release> $releases
     * @param array<string, array<array-key, mixed>> $composers
     */
    private function refuseDependents(array &$releases, array $composers): void
    {
        do {
            $refused = false;

            foreach ($this->versions as $package => $version) {
                if (isset($this->refusals[$package])) {
                    continue;
                }

                foreach (self::require($composers[$package] ?? []) as $name => $constraint) {
                    $dependency = $this->dependency($name);
                    $dependencyVersion = $dependency === null ? null : ($this->versions[$dependency] ?? null);

                    if ($dependency !== null && $dependencyVersion !== null && isset($this->refusals[$dependency])) {
                        $this->refusals[$package][] = \sprintf(
                            '%s %s: it requires %s %s, whose release %s is refused.',
                            $package,
                            $version->toString(),
                            $name,
                            $constraint,
                            $dependencyVersion->toString(),
                        );
                        unset($releases[$package]);
                        $refused = true;

                        break;
                    }
                }
            }
        } while ($refused);
    }

    /**
     * What the split did not write is an older package of the same name: a version or a branch alias
     * of the series would collide with it.
     *
     * @param non-empty-string $split
     * @return list<string>
     */
    private function foreignRefs(string $package, Version $version, Series $series, Refs $refs, string $split): array
    {
        $errors = [];

        $lowestAbove = ($series->major . '.' . ($series->minor + 1)) . '.0.0-dev';

        foreach ($refs->tagsFrom($series, except: $version) as $existing) {
            if (!$this->isOurs($package, $existing['commit'], $split)) {
                $errors[] = \sprintf(
                    'the tag %s is not in the history of main of the split: an older package of the same name has it.',
                    $existing['name'],
                );
            } elseif (
                Comparator::greaterThanOrEqualTo($existing['normalized'], $lowestAbove)
                && $this->isAncestor($existing['commit'], $split)
            ) {
                $errors[] = \sprintf(
                    'the tag %s of a higher series is in the history of the split: the series %s went back.',
                    $existing['name'],
                    $series->toString(),
                );
            }
        }

        $errors = [...$errors, ...$this->skippedSeries($series, $refs, $split)];

        $branch = $refs->branchOf($series);

        if ($branch !== null) {
            $errors[] = \sprintf(
                'the branch %s of an older package collides with the series %s.',
                $branch,
                $series->toString(),
            );
        }

        return $errors;
    }

    /**
     * A new series follows the highest series of the package: a typo like 0.3 for 0.2 would publish it for good.
     *
     * @param non-empty-string $split
     * @return list<string>
     */
    private function skippedSeries(Series $series, Refs $refs, string $split): array
    {
        if ($refs->highest($series->toString() . '.*', stable: false) !== null) {
            return [];
        }

        $previous = null;

        foreach ($refs->tagsBelow($series) as $existing) {
            $version = Version::parse($existing['name']);

            if ($version !== null
                && $this->isAncestor($existing['commit'], $split)
                && ($previous === null || Comparator::greaterThan($existing['normalized'], Refs::normalize($previous)))
            ) {
                $previous = $version;
            }
        }

        if ($previous === null) {
            return [];
        }

        $next = [$previous->major . '.' . ($previous->minor + 1), ($previous->major + 1) . '.0'];

        if (\in_array($series->toString(), $next, strict: true)) {
            return [];
        }

        return [\sprintf(
            'the series %s does not follow the series %d.%d of %s, expected %s.',
            $series->toString(),
            $previous->major,
            $previous->minor,
            $previous->tag(),
            implode(' or ', $next),
        )];
    }

    /**
     * @return list<string>
     */
    private static function notNext(Version $version, Series $series, Refs $refs): array
    {
        $last = $refs->highest($series->toString() . '.*', stable: false, except: $version);
        $lastVersion = $last === null ? null : Version::parse($last['name']);

        if ($last !== null && $lastVersion === null) {
            return [\sprintf('the last tag of the series, %s, is not a version the release writes.', $last['name'])];
        }

        $next = Version::next($series, $lastVersion);

        foreach ($next as $candidate) {
            if ($candidate->equals($version)) {
                return [];
            }
        }

        return [\sprintf(
            'it does not come right after %s, expected %s.',
            $last === null ? 'no version of the series ' . $series->toString() : $last['name'],
            implode(', ', array_map(static fn(Version $candidate) => $candidate->toString(), $next)),
        )];
    }

    /**
     * A forgotten new series of a dependent: the previous stable release of the series is installed with the ranges
     * it requires, so a new series of a dependency would reach its users with a patch.
     *
     * @param array<array-key, mixed> $composer
     * @return list<string>
     */
    private function changedRanges(Version $version, Series $series, Refs $refs, array $composer): array
    {
        $previous = $refs->highest($series->toString() . '.*', stable: true, except: $version);
        $content = $previous === null ? null : $this->catFile->read($previous['commit'] . ':' . self::COMPOSER, 'blob');

        if ($previous === null || $content === null) {
            return [];
        }

        $require = self::require($composer);
        $errors = [];

        foreach (self::require(Json::decode($content, $previous['name'] . ':' . self::COMPOSER)) as $name => $before) {
            $dependency = $this->dependency($name);
            $now = $require[$name] ?? null;

            if ($dependency === null || $now === null) {
                continue;
            }

            $dependencySeries = Series::ofComposer($this->composer($this->commit, $dependency));

            if ($dependencySeries !== null && !$dependencySeries->allows($before)) {
                $errors[] = \sprintf(
                    'it requires %s %s, %s required %s: a new series of a dependency needs a new series here.',
                    $name,
                    $now,
                    $previous['name'],
                    $before,
                );
            }
        }

        return $errors;
    }

    /**
     * The tests ran against the heads of the dependencies, and a user installs their tags.
     *
     * @param array<array-key, mixed> $composer
     * @return list<string>
     */
    private function unreleasedDependencies(Version $version, array $composer): array
    {
        $stable = !$version->preRelease;
        $errors = [];

        foreach (self::require($composer) as $name => $constraint) {
            $dependency = $this->dependency($name);

            if ($dependency === null) {
                continue;
            }

            $released = $this->versions[$dependency] ?? null;

            // A pre-release released with it does not count for a stable release: its users install a stable tag.
            if ($released !== null
                && (!$stable || !$released->preRelease)
                && Semver::satisfies(Refs::normalize($released), $constraint)
            ) {
                continue;
            }

            $tag = ($this->refs[$dependency] ?? null)?->highest($constraint, $stable, except: $released);
            $what = $stable ? 'a stable version' : 'a version';

            if ($tag === null) {
                $errors[] = \sprintf(
                    'it requires %s %s, which no %s tag meets: release %s of it too.',
                    $name,
                    $constraint,
                    $stable ? 'stable' : 'existing',
                    $what,
                );
            } elseif (!$this->shipsTheSame($dependency, $tag['commit'])) {
                $errors[] = \sprintf(
                    '%s changed since %s in the files it ships: release %s of it too.',
                    $name,
                    $tag['name'],
                    $what,
                );
            }
        }

        return $errors;
    }

    /**
     * Whether the files that composer installs (.gitattributes) are the same at the split and at the tag.
     */
    private function shipsTheSame(string $package, string $tag): bool
    {
        $split = $this->splits[$package] ?? '';
        $now = $this->shipped($split);
        $then = $this->shipped($tag);
        sort($now);
        sort($then);

        // A path that starts or stops being shipped changes the archive, though .gitattributes itself is not in it.
        if ($now !== $then) {
            return false;
        }

        $paths = $now;
        $diff = ['--literal-pathspecs', 'diff-tree', '--quiet', '-r', $tag, $split, '--', ...$paths];

        return $this->git->succeeds($diff);
    }

    /**
     * @return list<string>
     */
    private function shipped(string $commit): array
    {
        $attributes = $this->catFile->read($commit . ':.gitattributes', 'blob');
        preg_match_all('#^/(\S+) -export-ignore$#m', $attributes ?? '', $kept);

        return $kept[1] === [] ? ['.'] : $kept[1];
    }

    /**
     * A tag that the split wrote is at a split commit of main: at an ancestor of the split being released,
     * or at a later one, which only the split of a newer head of main has locally.
     */
    private function isOurs(string $package, string $tag, string $split): bool
    {
        if ($this->isAncestor($tag, $split)) {
            return true;
        }

        return array_any($this->mains, fn($main) => isset($main[$package]) && $this->isAncestor($tag, $main[$package]));
    }

    /**
     * actions/checkout of a push sets main and origin/main to the commit of the push, even when main has moved on:
     * only a fetch of origin finds the newer main. A clone without origin, or offline, has what it has.
     *
     * @return list<array<string, non-empty-string>>
     */
    private function splitMains(): array
    {
        $this->git->result(['fetch', '--quiet', '--no-tags', 'origin', '+' . Split::REF . ':' . self::ORIGIN_MAIN]);
        $mains = [];

        foreach ([Split::REF, self::ORIGIN_MAIN] as $ref) {
            $head = trim($this->git->result(['rev-parse', '--verify', '--quiet', $ref . '^{commit}'])->stdout);

            if ($head !== '' && $head !== $this->commit) {
                $mains[$head] = Splitter::split($this->git, $head);
            }
        }

        return array_values($mains);
    }

    private function isAncestor(string $ancestor, string $commit): bool
    {
        return $this->git->succeeds(['merge-base', '--is-ancestor', $ancestor, $commit]);
    }

    /**
     * Lists the refs of the split repositories of the packages, all at once, those listed already are skipped.
     *
     * @param list<string> $packages
     */
    private function fetchRefs(array $packages): void
    {
        $commands = [];

        foreach ($packages as $package) {
            if (!isset($this->listed[$package])) {
                $this->listed[$package] = true;
                $commands[$package] = ['ls-remote', $this->remote->url($package), 'refs/heads/*', 'refs/tags/*'];
            }
        }

        foreach ($this->git->with($this->remote->env)->runAll($commands) as $package => $result) {
            $repository = Repositories::ORGANIZATION . '/' . $package;

            if ($result->code !== 0) {
                $this->refusals[$package][] = \sprintf('%s: git ls-remote: %s', $repository, trim($result->stderr));

                continue;
            }

            $this->refs[$package] = Refs::parse($result->stdout, $repository);
        }
    }

    /**
     * @param array<array-key, mixed> $composer
     * @return list<non-empty-string> the directories of the monorepo packages it requires
     */
    private function dependencies(array $composer): array
    {
        $dependencies = [];

        foreach (array_keys(self::require($composer)) as $name) {
            $dependency = $this->dependency($name);

            if ($dependency !== null) {
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

    /**
     * @return ?non-empty-string the directory of a monorepo package at the commit
     */
    private function dependency(string $name): ?string
    {
        $prefix = Monorepo::VENDOR . '/';
        $package = str_starts_with($name, $prefix) ? substr($name, \strlen($prefix)) : '';

        return $package !== '' && isset($this->splits[$package]) ? $package : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function composer(string $commit, string $package): array
    {
        $path = self::path($package) . '/' . self::COMPOSER;
        $content = $this->catFile->read($commit . ':' . $path, 'blob')
            ?? throw new MonorepoException(\sprintf('%s: no %s at %s.', $package, $path, $commit));

        return Json::decode($content, $path);
    }

    /**
     * @return non-empty-string
     */
    private static function path(string $package): string
    {
        return Monorepo::PACKAGES_DIR . '/' . $package;
    }

    /**
     * @param array<array-key, mixed> $composer
     * @return array<string, string>
     */
    private static function require(array $composer): array
    {
        $require = $composer['require'] ?? null;
        $constraints = [];

        foreach (\is_array($require) ? $require : [] as $name => $constraint) {
            if (\is_string($constraint)) {
                $constraints[(string) $name] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * @param array<non-empty-string, Release> $releases
     * @return list<Release>
     */
    private static function dependenciesFirst(array $releases): array
    {
        ksort($releases);
        $ordered = [];

        while ($releases !== []) {
            $waiting = array_keys($releases);
            $ready = array_filter(
                $releases,
                static fn(Release $release) => array_intersect($release->requires, $waiting) === [],
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
