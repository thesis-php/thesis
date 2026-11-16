<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\GitHub;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * Whether the checks of the monorepo passed for a commit of main. A dispatch of the release does not run them again:
 * the image and the global tools float, so an old commit could fail checks that it passed. Only a run of a push
 * of main counts: a pull request with the same head tested its merge with another base.
 *
 * @internal
 */
final readonly class Checks
{
    public const string WORKFLOW = 'check.yaml';

    /**
     * The job of the release is in the same workflow: a release that failed leaves the checks passed.
     */
    public const string RELEASE_JOB = 'release';

    /**
     * @param non-empty-string $commit
     * @throws MonorepoException
     */
    public static function passed(Api $api, string $commit): bool
    {
        $repository = Repositories::ORGANIZATION . '/' . Repositories::MONOREPO;
        $runs = $api->get(\sprintf(
            'repos/%s/actions/workflows/%s/runs?head_sha=%s&event=push&branch=%s&per_page=100',
            $repository,
            self::WORKFLOW,
            $commit,
            Repositories::DEFAULT_BRANCH,
        ));

        foreach (self::list($runs, 'workflow_runs') as $run) {
            if (!\is_int($run['id'] ?? null) || ($run['status'] ?? null) !== 'completed') {
                continue;
            }

            $jobs = $api->get(\sprintf('repos/%s/actions/runs/%d/jobs?per_page=100', $repository, $run['id']));
            $checks = array_filter(
                self::list($jobs, 'jobs'),
                static fn(array $job) => ($job['name'] ?? null) !== self::RELEASE_JOB,
            );
            $failed = array_filter($checks, static fn(array $job) => ($job['conclusion'] ?? null) !== 'success');

            if ($checks !== [] && $failed === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ?array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>
     */
    private static function list(?array $response, string $key): array
    {
        $items = $response[$key] ?? null;

        return \is_array($items) ? array_values(array_filter($items, \is_array(...))) : [];
    }
}
