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
 * Decides what a push of main or a dispatch releases (agents/releases.md). Everything is read from the tree
 * of the commit and from the split repositories, nothing from the working tree: the tooling is that of the head
 * of main, the commit may be older. Everything is checked before anything is pushed, and a push is released whole
 * or not at all.
 *
 * @internal
 */
final class Plan
{
    private const string CHANGELOG = 'CHANGELOG.md';
    private const string COMPOSER = 'composer.json';
    private const string ORIGIN_MAIN = 'refs/remotes/origin/' . Repositories::DEFAULT_BRANCH;

    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * @var array<string, Refs> package => the refs of its split repository
     */
    private array $refs = [];

    /**
     * @var array<string, non-empty-string> package => its split commit
     */
    private array $splits = [];

    /**
     * @var array<string, Version> package => the version the push releases
     */
    private array $versions = [];

    /**
     * @var list<array<string, non-empty-string>> package => its split, of every head of main this clone knows
     */
    private array $mains = [];

    /**
     * @param non-empty-string $commit of main, resolved
     * @param ?non-empty-string $before the head of main before the push, resolved: the push releases the headings
     *     that its changelogs did not have
     * @param list<string> $packages without $before: release the version under [Unreleased] of each (a dispatch)
     * @return list<Release> dependencies first
     * @throws MonorepoException with every reason not to release, one per line
     */
    public static function of(
        Git $git,
        Api $api,
        Remote $remote,
        string $commit,
        ?string $before,
        array $packages,
    ): array {
        $plan = new self($git, $git->catFile(), $api, $remote, $commit);
        $changelogs = $plan->changelogs($before, $packages);

        if ($changelogs === []) {
            $plan->throwErrors();

            return [];
        }

        $plan->splits = Splitter::split($git, $commit);
        $plan->mains = $plan->splitMains();
        $composers = [];

        foreach ($changelogs as $package => $changelog) {
            $composers[$package] = $plan->composer($commit, $package);
            $plan->versions[$package] = $changelog->top()['version'];
        }

        $plan->fetchRefs(array_keys($changelogs), $composers);
        $releases = [];

        foreach ($changelogs as $package => $changelog) {
            $release = $plan->release($package, $changelog, $composers[$package] ?? []);

            if ($release !== null) {
                $releases[$package] = $release;
            }
        }

        $plan->throwErrors();

        return self::dependenciesFirst($releases, $composers);
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
     * @param list<string> $packages
     * @return array<non-empty-string, Changelog> package => its changelog at the commit, of what is released
     */
    private function changelogs(?string $before, array $packages): array
    {
        $existing = array_filter(
            array_map(strval(...), array_keys($this->catFile->subtrees($this->commit . ':' . Monorepo::PACKAGES_DIR))),
            static fn(string $package) => $package !== '',
        );
        $changelogs = [];

        if ($before === null) {
            foreach ($packages as $package) {
                if ($package === '' || !\in_array($package, $existing, strict: true)) {
                    $this->errors[] = \sprintf('%s: no such package at %s.', $package, $this->commit);

                    continue;
                }

                $changelog = $this->changelog($this->commit, $package);

                if ($changelog === null) {
                    $this->errors[] = \sprintf('%s: no %s at %s.', $package, self::CHANGELOG, $this->commit);

                    continue;
                }

                $changelogs[$package] = $changelog;
            }
        } else {
            foreach ($existing as $package) {
                $changelog = $this->changelog($this->commit, $package);
                $top = $changelog?->topVersion();
                $added = $top !== null && !$this->hadVersion($before, $package, $top);

                if ($changelog !== null && $added) {
                    $changelogs[$package] = $changelog;
                }
            }
        }

        foreach ($changelogs as $package => $changelog) {
            try {
                $changelog->top();
            } catch (MonorepoException $exception) {
                $this->errors[] = $exception->getMessage();
                unset($changelogs[$package]);
            }
        }

        return $this->errors === [] ? $changelogs : [];
    }

    /**
     * @param non-empty-string $package
     * @param array<array-key, mixed> $composer
     */
    private function release(string $package, Changelog $changelog, array $composer): ?Release
    {
        ['version' => $version, 'notes' => $notes] = $changelog->top();
        $split = $this->splits[$package] ?? throw new MonorepoException(\sprintf('%s: no split.', $package));
        $refs = $this->refs[$package] ?? null;
        $series = Series::ofComposer($composer);
        $errors = [];

        if ($changelog->unreleased !== '') {
            $errors[] = \sprintf('[Unreleased] of %s lists changes that are not part of it.', $changelog->path);
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

        $tag = $version->tag();
        $tagged = false;

        foreach ($refs?->tagsOf($version) ?? [] as $existing) {
            if ($existing['commit'] === $split) {
                $tag = $existing['name'];
                $tagged = true;
            } else {
                $errors[] = \sprintf(
                    'the tag %s exists at %s, not at the split %s.',
                    $existing['name'],
                    $existing['commit'],
                    $split,
                );
            }
        }

        // A tag at the split is published already: the checks below guard a new tag, and a later version
        // of the package or of a dependency must not keep a re-run from making the steps after it.
        if ($series !== null && $refs !== null && !$tagged) {
            $errors = [
                ...$errors,
                ...$this->foreignRefs($package, $version, $series, $refs, $split),
                ...self::notNext($version, $series, $refs),
                ...$this->changedRanges($version, $series, $refs, $composer),
            ];
        }

        if (!$tagged) {
            $errors = [...$errors, ...$this->unreleasedDependencies($version, $composer)];
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->errors[] = \sprintf('%s %s: %s', $package, $version->toString(), $error);
            }

            return null;
        }

        $highest = $refs?->highest('*', stable: true, except: $version);

        return new Release(
            package: $package,
            version: $version,
            tag: $tag,
            commit: $split,
            notes: $notes,
            tagged: $tagged,
            latest: !$version->preRelease
                && ($highest === null || Comparator::greaterThan(Refs::normalize($version), $highest['normalized'])),
        );
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

            // A pre-release of the same push does not count for a stable release: its users install a stable tag.
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
                    'it requires %s %s, which no %s tag meets: release %s of it in the same push.',
                    $name,
                    $constraint,
                    $stable ? 'stable' : 'existing',
                    $what,
                );
            } elseif (!$this->shipsTheSame($dependency, $tag['commit'])) {
                $errors[] = \sprintf(
                    '%s changed since %s in the files it ships: release %s of it in the same push.',
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
     * Lists the refs of every released package and of every monorepo package they require, all at once.
     *
     * @param list<non-empty-string> $packages
     * @param array<string, array<array-key, mixed>> $composers
     */
    private function fetchRefs(array $packages, array $composers): void
    {
        $repositories = $packages;

        foreach ($composers as $composer) {
            foreach (array_keys(self::require($composer)) as $name) {
                $dependency = $this->dependency($name);

                if ($dependency !== null) {
                    $repositories[] = $dependency;
                }
            }
        }

        $commands = [];

        foreach (array_unique($repositories) as $package) {
            $commands[$package] = ['ls-remote', $this->remote->url($package), 'refs/heads/*', 'refs/tags/*'];
        }

        foreach ($this->git->with($this->remote->env)->runAll($commands) as $package => $result) {
            $repository = Repositories::ORGANIZATION . '/' . $package;

            if ($result->code !== 0) {
                $this->errors[] = \sprintf('%s: git ls-remote: %s', $repository, trim($result->stderr));

                continue;
            }

            $this->refs[$package] = Refs::parse($result->stdout, $repository);
        }
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
     * A changelog that the push repaired is read by its headings alone: the push is not refused for a state
     * its head no longer has, and a version it had before is not added by the repair.
     */
    private function hadVersion(string $commit, string $package, string $version): bool
    {
        $path = self::path($package) . '/' . self::CHANGELOG;
        $content = $this->catFile->read($commit . ':' . $path, 'blob');

        if ($content === null) {
            return false;
        }

        try {
            return Changelog::parse($content, $path)->has($version);
        } catch (MonorepoException) {
            return preg_match('/^## \[' . preg_quote($version, '/') . '\]/m', $content) === 1;
        }
    }

    private function changelog(string $commit, string $package): ?Changelog
    {
        $path = self::path($package) . '/' . self::CHANGELOG;
        $content = $this->catFile->read($commit . ':' . $path, 'blob');

        try {
            return $content === null ? null : Changelog::parse($content, $path);
        } catch (MonorepoException $exception) {
            $this->errors[] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return non-empty-string
     */
    private static function path(string $package): string
    {
        return Monorepo::PACKAGES_DIR . '/' . $package;
    }

    private function throwErrors(): void
    {
        if ($this->errors !== []) {
            throw new MonorepoException(implode("\n", $this->errors));
        }
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
     * @param array<string, array<array-key, mixed>> $composers
     * @return list<Release>
     */
    private static function dependenciesFirst(array $releases, array $composers): array
    {
        ksort($releases);
        $prefix = Monorepo::VENDOR . '/';
        $dependencies = [];

        foreach (array_keys($releases) as $package) {
            foreach (array_keys(self::require($composers[$package] ?? [])) as $name) {
                if (str_starts_with($name, $prefix)) {
                    $dependencies[$package][] = substr($name, \strlen($prefix));
                }
            }
        }

        $ordered = [];

        while ($releases !== []) {
            $waiting = array_keys($releases);
            $ready = array_filter(
                $releases,
                static fn(Release $release) => array_intersect($dependencies[$release->package] ?? [], $waiting) === [],
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
