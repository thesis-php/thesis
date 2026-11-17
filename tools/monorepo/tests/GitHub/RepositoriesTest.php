<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\GitHub;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Tests\Fixture;

#[Test]
final readonly class RepositoriesTest
{
    public static function createsMissingRepositoryWithSettingsAndRulesets(): void
    {
        $api = new FakeApi(self::monorepoInSync());

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, [
            'POST orgs/thesis-php/repos',
            'PATCH repos/thesis-php/io',
            'POST repos/thesis-php/io/rulesets',
            'POST repos/thesis-php/io/rulesets',
            'POST repos/thesis-php/io/hooks',
        ]);
        Assert::same($api->bodies['POST orgs/thesis-php/repos'] ?? null, ['name' => 'io', 'visibility' => 'public']);
        Assert::same($api->bodies['PATCH repos/thesis-php/io'] ?? null, self::settings());
        Assert::same($changes, [
            'thesis-php/io: created',
            'thesis-php/io: description, homepage, has_issues, has_wiki, has_projects, has_discussions, '
                . 'has_pull_requests updated',
            'thesis-php/io: ruleset "read-only branches" created',
            'thesis-php/io: ruleset "read-only tags" created',
            'thesis-php/io: Packagist webhook created',
        ]);
    }

    public static function changesNothingInSync(): void
    {
        $api = new FakeApi(self::inSync());

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, []);
        Assert::same($changes, []);
    }

    public static function updatesOnlyStaleSettings(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io'] = [...self::repository(), 'description' => 'Old.', 'has_issues' => true];
        $api = new FakeApi($resources);

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, ['PATCH repos/thesis-php/io']);
        Assert::same($api->bodies['PATCH repos/thesis-php/io'] ?? null, [
            'description' => 'Package io.',
            'has_issues' => false,
        ]);
        Assert::same($changes, ['thesis-php/io: description, has_issues updated']);
    }

    public static function setsDefaultBranchOnceMainExists(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io'] = [...self::repository(), 'default_branch' => 'master'];
        $resources['repos/thesis-php/io/branches/main'] = ['name' => 'main'];
        $api = new FakeApi($resources);

        Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false);

        Assert::same($api->writes, ['PATCH repos/thesis-php/io']);
        Assert::same($api->bodies['PATCH repos/thesis-php/io'] ?? null, ['default_branch' => 'main']);
    }

    public static function leavesDefaultBranchWithoutMain(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io'] = [...self::repository(), 'default_branch' => 'master'];
        $api = new FakeApi($resources);

        Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false);

        Assert::same($api->writes, []);
    }

    public static function updatesChangedRuleset(): void
    {
        $resources = self::inSync();
        $ruleset = self::ruleset(2, 'read-only tags', 'tag');
        $resources['repos/thesis-php/io/rulesets/2'] = [...$ruleset, 'bypass_actors' => []];
        $api = new FakeApi($resources);

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, ['PUT repos/thesis-php/io/rulesets/2']);
        Assert::same($changes, ['thesis-php/io: ruleset "read-only tags" updated']);
    }

    public static function refusesRepositoryThatIsNotSplitAndSyncsTheOthers(): void
    {
        $api = new FakeApi([
            ...self::monorepoInSync(),
            'repos/thesis-php/io' => ['full_name' => 'thesis-php/io', 'default_branch' => '0.2.x'],
            'repos/thesis-php/io/rulesets' => [],
        ]);

        $synced = Repositories::sync(
            monorepo: new Fixture()->package('io')->package('net')->load(),
            api: $api,
            packagist: new FakePackagist(),
            dryRun: false,
        );

        Assert::same($synced->errors, [
            'thesis-php/io exists, but is not a split of packages/io: it has no rulesets of the split. Rename or delete it, or add the rulesets by hand to take it over.',
        ]);
        Assert::same($api->writes, [
            'POST orgs/thesis-php/repos',
            'PATCH repos/thesis-php/net',
            'POST repos/thesis-php/net/rulesets',
            'POST repos/thesis-php/net/rulesets',
            'POST repos/thesis-php/net/hooks',
        ]);
    }

    public static function createsRepositoryWhoseNameRedirectsToRenamedOne(): void
    {
        $api = new FakeApi([
            ...self::monorepoInSync(),
            'repos/thesis-php/io' => ['full_name' => 'thesis-php/io-legacy', 'default_branch' => '0.2.x'],
        ]);

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes[0] ?? null, 'POST orgs/thesis-php/repos');
        Assert::same($changes[0] ?? null, 'thesis-php/io: created, the redirect to thesis-php/io-legacy is gone');
    }

    public static function createsPackagistWebhookWhenMissing(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io/hooks'] = [['config' => ['url' => 'https://example.com/hook']]];
        $api = new FakeApi($resources);

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, ['POST repos/thesis-php/io/hooks']);
        Assert::same($api->bodies['POST repos/thesis-php/io/hooks'] ?? null, [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url' => 'https://packagist.org/api/github?username=thesis',
                'content_type' => 'json',
                'secret' => 'token',
            ],
        ]);
        Assert::same($changes, ['thesis-php/io: Packagist webhook created']);
    }

    public static function keepsWebhookOfPackagistIntegration(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io/hooks'] = [['config' => ['url' => 'https://packagist.org/api/github']]];
        $api = new FakeApi($resources);

        Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false);

        Assert::same($api->writes, []);
    }

    public static function registersOnPackagistOnceMainExists(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io/branches/main'] = ['name' => 'main'];
        $packagist = new FakePackagist();

        $changes = Repositories::sync(self::monorepo(), new FakeApi($resources), $packagist, dryRun: false)->changes;

        Assert::same($packagist->created, ['https://github.com/thesis-php/io']);
        Assert::same($changes, ['thesis-php/io: thesis/io registered on Packagist']);
    }

    public static function leavesPackagistWithoutMain(): void
    {
        $packagist = new FakePackagist();

        Repositories::sync(self::monorepo(), new FakeApi(self::inSync()), $packagist, dryRun: false);

        Assert::same($packagist->created, []);
    }

    public static function registersNothingInDryRun(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io/branches/main'] = ['name' => 'main'];
        $packagist = new FakePackagist();

        $changes = Repositories::sync(self::monorepo(), new FakeApi($resources), $packagist, dryRun: true)->changes;

        Assert::same($packagist->created, []);
        Assert::same($changes, ['thesis-php/io: thesis/io registered on Packagist']);
    }

    public static function failureOfPackagistIsErrorOfItsRepository(): void
    {
        $resources = self::inSync();
        $resources['repos/thesis-php/io/branches/main'] = ['name' => 'main'];

        $synced = Repositories::sync(
            monorepo: self::monorepo(),
            api: new FakeApi($resources),
            packagist: new FakePackagist(failing: 'Packagist: down.'),
            dryRun: false,
        );

        Assert::same($synced->errors, ['Packagist: down.']);
    }

    public static function writesNothingInDryRun(): void
    {
        $api = new FakeApi(self::monorepoInSync());

        $changes = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: true)->changes;

        Assert::same($api->writes, []);
        Assert::same(\count($changes), 5);
    }

    public static function setsTopicsFromKeywords(): void
    {
        $monorepo = new Fixture()->package('io', "keywords: ['php', 'io']")->load();
        $api = new FakeApi(self::inSync());

        $changes = Repositories::sync($monorepo, $api, new FakePackagist(['thesis/io']), dryRun: false)->changes;

        Assert::same($api->writes, ['PUT repos/thesis-php/io/topics']);
        Assert::same($api->bodies['PUT repos/thesis-php/io/topics'] ?? null, ['names' => ['php', 'io']]);
        Assert::same($changes, ['thesis-php/io: topics updated']);
    }

    public static function leavesTopicsInAnotherOrder(): void
    {
        $monorepo = new Fixture()->package('io', "keywords: ['php', 'io']")->load();
        $resources = self::inSync();
        $resources['repos/thesis-php/io'] = [...self::repository(), 'topics' => ['io', 'php']];
        $api = new FakeApi($resources);

        Repositories::sync($monorepo, $api, new FakePackagist(['thesis/io']), dryRun: false);

        Assert::same($api->writes, []);
    }

    public static function setsDescriptionAndTopicsOfMonorepo(): void
    {
        $monorepo = new Fixture()
            ->file('monorepo.php', <<<'PHP'
                <?php

                return new Thesis\Monorepo\Config(
                    php: '^8.5',
                    description: 'New.',
                    copyrightHolder: 'Fixture',
                    licenseSince: 2026,
                    keywords: ['php', 'monorepo'],
                );
                PHP)
            ->load()
        ;
        $api = new FakeApi([
            'repos/thesis-php/thesis' => [
                'full_name' => 'thesis-php/thesis',
                'description' => 'Old.',
                'topics' => ['php'],
            ],
        ]);

        $changes = Repositories::sync($monorepo, $api, new FakePackagist(), dryRun: false)->changes;

        Assert::same($api->bodies, [
            'PATCH repos/thesis-php/thesis' => ['description' => 'New.'],
            'PUT repos/thesis-php/thesis/topics' => ['names' => ['php', 'monorepo']],
        ]);
        Assert::same($changes, ['thesis-php/thesis: description updated', 'thesis-php/thesis: topics updated']);
    }

    public static function failsOnMonorepoItCannotSeeAndSyncsThePackages(): void
    {
        $api = new FakeApi(array_diff_key(self::inSync(), self::monorepoInSync()));

        $synced = Repositories::sync(self::monorepo(), $api, new FakePackagist(['thesis/io']), dryRun: false);

        Assert::same($synced->errors, ['thesis-php/thesis does not exist or the token has no access to it.']);
        Assert::same($api->writes, []);
    }

    private static function monorepo(): Monorepo
    {
        return new Fixture()->package('io')->load();
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private static function inSync(): array
    {
        return [
            ...self::monorepoInSync(),
            'repos/thesis-php/io' => self::repository(),
            'repos/thesis-php/io/rulesets' => [
                ['id' => 1, 'name' => 'read-only branches'],
                ['id' => 2, 'name' => 'read-only tags'],
            ],
            'repos/thesis-php/io/rulesets/1' => self::ruleset(1, 'read-only branches', 'branch'),
            'repos/thesis-php/io/rulesets/2' => self::ruleset(2, 'read-only tags', 'tag'),
            'repos/thesis-php/io/hooks' => [
                ['id' => 3, 'config' => ['url' => 'https://packagist.org/api/github?username=thesis']],
            ],
        ];
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private static function monorepoInSync(): array
    {
        return ['repos/thesis-php/thesis' => ['full_name' => 'thesis-php/thesis', 'description' => 'Fixture.']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function repository(): array
    {
        return ['id' => 42, 'full_name' => 'thesis-php/io', 'default_branch' => 'main', ...self::settings()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        return [
            'description' => 'Package io.',
            'homepage' => 'https://github.com/thesis-php/thesis/tree/main/packages/io',
            'has_issues' => false,
            'has_wiki' => false,
            'has_projects' => false,
            'has_discussions' => false,
            'has_pull_requests' => false,
        ];
    }

    /**
     * The shape GitHub returns: more keys than are sent, the keys of conditions in another order.
     *
     * @return array<string, mixed>
     */
    private static function ruleset(int $id, string $name, string $target): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'target' => $target,
            'source_type' => 'Repository',
            'enforcement' => 'active',
            'conditions' => ['ref_name' => ['exclude' => [], 'include' => ['~ALL']]],
            'rules' => [
                ['type' => 'creation'],
                ['type' => 'update'],
                ['type' => 'deletion'],
                ['type' => 'non_fast_forward'],
            ],
            'bypass_actors' => [['actor_id' => 5766114, 'actor_type' => 'Integration', 'bypass_mode' => 'always']],
            'current_user_can_bypass' => 'never',
        ];
    }
}
