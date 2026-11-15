<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\GitHub;

use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Package;

/**
 * Brings thesis-php/<dir> of every package to the state derived from its manifest: a public read-only copy
 * of packages/<dir> that only the app of the split may push to. Deletes nothing. A repository that fails
 * does not stop the others: the split still pushes every package whose repository is fine.
 *
 * @internal
 */
final class Repositories
{
    public const string ORGANIZATION = 'thesis-php';
    public const string MONOREPO = 'thesis';
    public const string DEFAULT_BRANCH = 'main';

    /**
     * The GitHub App that pushes the splits: the only actor the rulesets let through.
     */
    public const int BOT_APP_ID = 5766114;

    /**
     * @var list<non-empty-string> what was changed, or would be with
     */
    public private(set) array $changes = [];

    /**
     * @var list<string> one per repository that could not be synced
     */
    public private(set) array $errors = [];

    public static function sync(Monorepo $monorepo, Api $api, bool $dryRun): self
    {
        $repositories = new self($api, $dryRun);

        foreach ($monorepo->packages as $package) {
            try {
                $repositories->repository($package);
            } catch (MonorepoException $exception) {
                $repositories->errors[] = $exception->getMessage();
            }
        }

        return $repositories;
    }

    /**
     * Whether thesis-php/<dir> is a split, so the split may push to it. An older package of the same name has
     * no rulesets of the split, and a push of a branch it lacks (main) would create it instead of failing.
     */
    public static function isSplit(Api $api, string $dir): bool
    {
        return self::hasSplitRulesets(self::rulesetIds($api, self::ORGANIZATION . '/' . $dir));
    }

    private function __construct(
        private readonly Api $api,
        private readonly bool $dryRun,
    ) {}

    private function repository(Package $package): void
    {
        $repository = self::ORGANIZATION . '/' . $package->dir;
        $actual = $this->api->get('repos/' . $repository);
        $created = 'created';

        // GitHub redirects the old name of a renamed repository to it: the name is free,
        // and taking it ends the redirect.
        $fullName = $actual['full_name'] ?? null;

        if (\is_string($fullName) && strcasecmp($fullName, $repository) !== 0) {
            $actual = null;
            $created .= ', the redirect to ' . $fullName . ' is gone';
        }

        $rulesets = $actual === null ? [] : self::rulesetIds($this->api, $repository);

        // A repository of the same name may be an older package that is not a split: it must not be taken over.
        if ($actual !== null && !self::hasSplitRulesets($rulesets)) {
            throw new MonorepoException(\sprintf(
                '%s exists, but is not a split of %s: it has no rulesets of the split. '
                    . 'Rename or delete it, or add the rulesets by hand to take it over.',
                $repository,
                $package->path(),
            ));
        }

        if ($actual === null) {
            $this->change(
                repository: $repository,
                what: $created,
                apply: fn() => $this->api->send('POST', 'orgs/' . self::ORGANIZATION . '/repos', [
                    'name' => $package->dir,
                    'visibility' => 'public',
                ]),
            );
        }

        $current = $actual ?? [];
        $stale = array_filter(
            self::settings($package),
            static fn(mixed $value, string $key) => ($current[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($stale !== []) {
            $this->change(
                repository: $repository,
                what: implode(', ', array_keys($stale)) . ' updated',
                apply: fn() => $this->api->send('PATCH', 'repos/' . $repository, $stale),
            );
        }

        // A new repository gets the default branch from its first push, the split of main.
        if (
            $actual !== null
            && ($actual['default_branch'] ?? null) !== self::DEFAULT_BRANCH
            && $this->api->get(\sprintf('repos/%s/branches/%s', $repository, self::DEFAULT_BRANCH)) !== null
        ) {
            $this->change(
                repository: $repository,
                what: 'default branch set to ' . self::DEFAULT_BRANCH,
                apply: fn() => $this->api->send('PATCH', 'repos/' . $repository, [
                    'default_branch' => self::DEFAULT_BRANCH,
                ]),
            );
        }

        $this->rulesets($repository, existing: $rulesets);
    }

    /**
     * @param non-empty-string $repository
     * @param array<string, int> $existing name => id
     */
    private function rulesets(string $repository, array $existing): void
    {
        foreach (self::desiredRulesets() as $name => $ruleset) {
            $id = $existing[$name] ?? null;

            if ($id === null) {
                $this->change(
                    repository: $repository,
                    what: \sprintf('ruleset "%s" created', $name),
                    apply: fn() => $this->api->send('POST', \sprintf('repos/%s/rulesets', $repository), $ruleset),
                );

                continue;
            }

            $path = \sprintf('repos/%s/rulesets/%d', $repository, $id);

            if (!self::matches($this->api->get($path) ?? [], $ruleset)) {
                $this->change(
                    repository: $repository,
                    what: \sprintf('ruleset "%s" updated', $name),
                    apply: fn() => $this->api->send('PUT', $path, $ruleset),
                );
            }
        }
    }

    /**
     * @param non-empty-string $repository
     * @return array<string, int> name => id
     */
    private static function rulesetIds(Api $api, string $repository): array
    {
        $ids = [];

        foreach ($api->get(\sprintf('repos/%s/rulesets', $repository)) ?? [] as $ruleset) {
            if (\is_array($ruleset) && \is_string($ruleset['name'] ?? null) && \is_int($ruleset['id'] ?? null)) {
                $ids[$ruleset['name']] = $ruleset['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int> $ids name => id
     */
    private static function hasSplitRulesets(array $ids): bool
    {
        return array_intersect_key($ids, self::desiredRulesets()) !== [];
    }

    /**
     * @param non-empty-string $repository
     * @param non-empty-string $what
     * @param \Closure(): mixed $apply
     */
    private function change(string $repository, string $what, \Closure $apply): void
    {
        if (!$this->dryRun) {
            $apply();
        }

        $this->changes[] = $repository . ': ' . $what;
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private static function settings(Package $package): array
    {
        return [
            'description' => $package->manifest->description,
            'homepage' => \sprintf(
                'https://github.com/%s/%s/tree/%s/%s',
                self::ORGANIZATION,
                self::MONOREPO,
                self::DEFAULT_BRANCH,
                $package->path(),
            ),
            'has_issues' => false,
            'has_wiki' => false,
            'has_projects' => false,
            'has_discussions' => false,
            'has_pull_requests' => false,
        ];
    }

    /**
     * Two rulesets, as a ruleset has one target: branches or tags.
     *
     * @return array<non-empty-string, array<string, mixed>> name => ruleset
     */
    private static function desiredRulesets(): array
    {
        $rulesets = [];

        foreach (['branch' => 'branches', 'tag' => 'tags'] as $target => $refs) {
            $name = 'read-only ' . $refs;
            $rulesets[$name] = [
                'name' => $name,
                'target' => $target,
                'enforcement' => 'active',
                'bypass_actors' => [
                    ['actor_id' => self::BOT_APP_ID, 'actor_type' => 'Integration', 'bypass_mode' => 'always'],
                ],
                'conditions' => ['ref_name' => ['include' => ['~ALL'], 'exclude' => []]],
                'rules' => [
                    ['type' => 'creation'],
                    ['type' => 'update'],
                    ['type' => 'deletion'],
                    ['type' => 'non_fast_forward'],
                ],
            ];
        }

        return $rulesets;
    }

    /**
     * GitHub returns more keys than are sent and its own order of keys.
     *
     * @param array<array-key, mixed> $actual
     * @param array<string, mixed> $desired
     */
    private static function matches(array $actual, array $desired): bool
    {
        return Json::canonical(array_intersect_key($actual, $desired)) === Json::canonical($desired);
    }
}
