<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\GitHub\Checks;
use Thesis\Monorepo\Tests\GitHub\FakeApi;

#[Test]
final readonly class ChecksTest
{
    private const string COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /**
     * The id of a run that has not completed.
     */
    private const int RUNNING = 9;

    public static function passesRunWhoseChecksSucceededWhateverItsRelease(): void
    {
        $api = self::api([7 => ['check' => 'success', 'test (8.5, Ev)' => 'success', 'release' => 'failure']]);

        Assert::true(Checks::passed($api, self::COMMIT));
    }

    /**
     * A run of a dispatch skips the checks: it must never count as a passed one.
     */
    public static function failsRunWhoseChecksWereSkipped(): void
    {
        $api = self::api([7 => ['check' => 'skipped', 'test (8.5, Ev)' => 'skipped', 'release' => 'success']]);

        Assert::false(Checks::passed($api, self::COMMIT));
    }

    public static function failsRunThatIsStillRunning(): void
    {
        $api = self::api([7 => ['check' => 'success', 'test (8.5, Ev)' => null]]);

        Assert::false(Checks::passed($api, self::COMMIT));
    }

    public static function failsRunThatHasNotCompletedThoughItsJobsSucceeded(): void
    {
        $api = self::api([self::RUNNING => ['check' => 'success', 'test (8.5, Ev)' => 'success']]);

        Assert::false(Checks::passed($api, self::COMMIT));
    }

    public static function failsRunWithReleaseOnly(): void
    {
        Assert::false(Checks::passed(self::api([7 => ['release' => 'success']]), self::COMMIT));
    }

    public static function passesWhenAnyRunPassed(): void
    {
        $api = self::api([
            7 => ['check' => 'success', 'test (8.5, Ev)' => 'failure'],
            8 => ['check' => 'success', 'test (8.5, Ev)' => 'success'],
        ]);

        Assert::true(Checks::passed($api, self::COMMIT));
    }

    public static function failsWithoutRuns(): void
    {
        Assert::false(Checks::passed(new FakeApi(), self::COMMIT));
    }

    /**
     * @param array<int, array<string, ?string>> $runs id => job => conclusion
     */
    private static function api(array $runs): FakeApi
    {
        $resources = [
            'repos/thesis-php/thesis/actions/workflows/check.yaml/runs?head_sha=' . self::COMMIT
                . '&event=push&branch=main&per_page=100' => [
                    'workflow_runs' => array_map(
                        static fn(int $id) => [
                            'id' => $id,
                            'status' => $id === self::RUNNING ? 'in_progress' : 'completed',
                        ],
                        array_keys($runs),
                    ),
                ],
        ];

        foreach ($runs as $id => $jobs) {
            $resources['repos/thesis-php/thesis/actions/runs/' . $id . '/jobs?per_page=100'] = [
                'jobs' => array_map(
                    static fn(string $name, ?string $conclusion) => ['name' => $name, 'conclusion' => $conclusion],
                    array_keys($jobs),
                    $jobs,
                ),
            ];
        }

        return new FakeApi($resources);
    }
}
