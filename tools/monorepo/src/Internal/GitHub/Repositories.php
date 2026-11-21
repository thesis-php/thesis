<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\GitHub;

use Thesis\Monorepo\Config;
use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Package;
use Thesis\Monorepo\Internal\Packagist\Packagist;

/**
 * Brings thesis-php/<dir> of every package to the state derived from its manifest: a public read-only copy
 * of packages/<dir> that only the app of the split may push to, registered on Packagist. Deletes nothing.
 * Of the monorepo itself only the description and the topics come from monorepo.php.
 * A repository that fails does not stop the others: the split still pushes every package whose repository is fine.
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
     * Packages whose repositories neither github nor split touch: thesis-php/amqp is an older package
     * without the rulesets of the split, and taking it over waits until amqp is ready to be published.
     * TODO: delete once amqp is ready.
     */
    public const array HELD_BACK = ['amqp'];

    /**
     * @var list<non-empty-string> what was changed, or would be with
     */
    public private(set) array $changes = [];

    /**
     * @var list<string> one per repository that could not be synced
     */
    public private(set) array $errors = [];

    public static function sync(Monorepo $monorepo, Api $api, Packagist $packagist, bool $dryRun): self
    {
        $repositories = new self($api, $packagist, $dryRun);

        try {
            $repositories->monorepo($monorepo->config);
        } catch (MonorepoException $exception) {
            $repositories->errors[] = $exception->getMessage();
        }

        foreach ($monorepo->packages as $package) {
            if (\in_array($package->dir, self::HELD_BACK, strict: true)) {
                continue;
            }

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
     * A name that redirects to a renamed repository is not a split either: the API would return the rulesets
     * of that repository, and git would push there too.
     */
    public static function isSplit(Api $api, string $dir): bool
    {
        $repository = self::ORGANIZATION . '/' . $dir;

        return self::isNamed($api->get('repos/' . $repository), $repository)
            && self::hasSplitRulesets(self::rulesetIds($api, $repository));
    }

    private function __construct(
        private readonly Api $api,
        private readonly Packagist $packagist,
        private readonly bool $dryRun,
    ) {}

    private function monorepo(Config $config): void
    {
        $repository = self::ORGANIZATION . '/' . self::MONOREPO;
        $actual = $this->api->get('repos/' . $repository);

        if ($actual === null || !self::isNamed($actual, $repository)) {
            throw new MonorepoException(\sprintf('%s does not exist or the token has no access to it.', $repository));
        }

        if (($actual['description'] ?? null) !== $config->description) {
            $this->change(
                repository: $repository,
                what: 'description updated',
                apply: fn() => $this->api->send('PATCH', 'repos/' . $repository, [
                    'description' => $config->description,
                ]),
            );
        }

        $this->topics($repository, $actual, $config->keywords);
    }

    private function repository(Package $package): void
    {
        $repository = self::ORGANIZATION . '/' . $package->dir;
        $actual = $this->api->get('repos/' . $repository);
        $created = 'created';

        // GitHub redirects the old name of a renamed repository to it: the name is free,
        // and taking it ends the redirect.
        if ($actual !== null && !self::isNamed($actual, $repository)) {
            $created .= ', the redirect to ' . self::fullName($actual) . ' is gone';
            $actual = null;
        }

        $rulesets = $actual === null ? [] : self::rulesetIds($this->api, $repository);

        // A repository of the same name may be an older package that is not a split: it must not be taken over.
        if ($actual !== null && !self::hasSplitRulesets($rulesets)) {
            throw new MonorepoException(\sprintf(
                '%s exists, but is not a split of %s: it has no rulesets of the split. '
                    . 'Rename or delete it, or add the rulesets by hand to take it over.',
                $repository,
                $package->path,
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

        // A new repository gets main from its first push, the split, after this run.
        $hasMain = $actual !== null
            && $this->api->get(\sprintf('repos/%s/branches/%s', $repository, self::DEFAULT_BRANCH)) !== null;

        if ($hasMain && ($actual['default_branch'] ?? null) !== self::DEFAULT_BRANCH) {
            $this->change(
                repository: $repository,
                what: 'default branch set to ' . self::DEFAULT_BRANCH,
                apply: fn() => $this->api->send('PATCH', 'repos/' . $repository, [
                    'default_branch' => self::DEFAULT_BRANCH,
                ]),
            );
        }

        $this->topics($repository, $current, $package->manifest->keywords);
        $this->rulesets($repository, existing: $rulesets);
        $this->packagist($package, $repository, $hasMain);
    }

    /**
     * Compared as a set: GitHub keeps the topics in its own order.
     *
     * @param non-empty-string $repository
     * @param array<array-key, mixed> $actual what GET repos/<name> returns
     * @param list<non-empty-string> $keywords
     */
    private function topics(string $repository, array $actual, array $keywords): void
    {
        $current = \is_array($actual['topics'] ?? null) ? $actual['topics'] : [];
        sort($current);
        $desired = $keywords;
        sort($desired);

        if ($current !== $desired) {
            $this->change(
                repository: $repository,
                what: 'topics updated',
                apply: fn() => $this->api->send('PUT', \sprintf('repos/%s/topics', $repository), [
                    'names' => $keywords,
                ]),
            );
        }
    }

    /**
     * The webhook goes first: a push after the registration is not missed. A package is registered once main
     * exists: Packagist reads the composer.json of the repository right away and refuses one without it.
     *
     * @param non-empty-string $repository
     */
    private function packagist(Package $package, string $repository, bool $hasMain): void
    {
        if (!self::hasPackagistHook($this->api->get(\sprintf('repos/%s/hooks', $repository)) ?? [])) {
            $this->change(
                repository: $repository,
                what: 'Packagist webhook created',
                apply: fn() => $this->api->send('POST', \sprintf('repos/%s/hooks', $repository), [
                    'name' => 'web',
                    'active' => true,
                    'events' => ['push'],
                    'config' => $this->packagist->hook(),
                ]),
            );
        }

        if ($hasMain && !$this->packagist->exists($package->name)) {
            $this->change(
                repository: $repository,
                what: \sprintf('%s registered on Packagist', $package->name),
                apply: fn() => $this->packagist->create('https://github.com/' . $repository),
            );
        }
    }

    /**
     * Any Packagist webhook works. Older packages use the Packagist GitHub integration webhook.
     *
     * @param array<array-key, mixed> $hooks what GET repos/<name>/hooks returns
     */
    private static function hasPackagistHook(array $hooks): bool
    {
        foreach ($hooks as $hook) {
            $url = \is_array($hook) && \is_array($hook['config'] ?? null) ? $hook['config']['url'] ?? null : null;

            if (\is_string($url) && str_starts_with($url, Packagist::HOOK_URL)) {
                return true;
            }
        }

        return false;
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
     * @param ?array<array-key, mixed> $repository what GET repos/<name> returns
     * @param non-empty-string $name
     */
    private static function isNamed(?array $repository, string $name): bool
    {
        return $repository !== null && strcasecmp(self::fullName($repository), $name) === 0;
    }

    /**
     * @param array<array-key, mixed> $repository what GET repos/<name> returns
     */
    private static function fullName(array $repository): string
    {
        $fullName = $repository['full_name'] ?? '';

        return \is_string($fullName) ? $fullName : '';
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
     * @param callable(): mixed $apply
     */
    private function change(string $repository, string $what, callable $apply): void
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
                $package->path,
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
