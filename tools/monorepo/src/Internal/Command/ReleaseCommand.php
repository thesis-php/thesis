<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Release\Plan;
use Thesis\Monorepo\Internal\Release\Publisher;
use Thesis\Monorepo\Internal\Release\Release;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Internal\Split\Split;

/**
 * @internal
 */
#[AsCommand('release', 'Release the changelog headings of main that have no tag yet')]
final readonly class ReleaseCommand
{
    /**
     * @param string $remote where the split repositories are, with the credentials to push when $push
     * @param bool $push false to only print what would be released
     * @param ?Api $api GITHUB_TOKEN of the app: the split repositories and their releases
     */
    public function __construct(
        private string $root,
        private string $remote,
        private bool $push,
        private ?Api $api,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A commit of main whose checks passed')]
        string $commit,
    ): int {
        $git = new Git($this->root);

        try {
            $remote = Remote::parse($this->remote);
            $api = $this->api ?? throw new MonorepoException('Set GITHUB_TOKEN: it reads the split repositories.');
            $commit = self::commit($git, $commit);

            if (!$git->succeeds(['merge-base', '--is-ancestor', $commit, Split::REF])) {
                throw new MonorepoException(\sprintf('%s is not reachable from %s.', $commit, Split::REF));
            }

            $plan = Plan::of($git, $api, $remote, $commit);
        } catch (MonorepoException $exception) {
            // Raw and unwrapped: one reason per line, with paths and versions that must stay whole.
            $io->writeln($exception->getMessage(), OutputInterface::OUTPUT_RAW);
            $io->error('Nothing is released.');

            return Command::FAILURE;
        }

        foreach ($plan->refusals as $reasons) {
            $io->writeln(implode("\n", $reasons), OutputInterface::OUTPUT_RAW);
        }

        if ($plan->releases === [] && $plan->refusals === []) {
            $io->writeln('Nothing to release.');

            return Command::SUCCESS;
        }

        foreach ($plan->releases as $release) {
            $io->writeln(\sprintf('%s: %s at %s', $release->package, $release->tag, $release->commit));
        }

        $failed = $this->push ? self::publish($io, $git, $remote, $api, $plan->releases) : [];

        if (!$this->push && $plan->releases !== []) {
            $io->writeln('Set SPLIT_REMOTE to release.');
        }

        if ($plan->refusals !== []) {
            $io->error(\sprintf(
                'Not released: %s. The next push of main that passes the checks releases what it fixes.',
                implode(', ', array_keys($plan->refusals)),
            ));
        }

        if ($failed !== []) {
            $io->error(\sprintf(
                'The release of %s failed. Re-run the job, or the next push of main releases it.',
                implode(', ', $failed),
            ));
        }

        return $plan->refusals === [] && $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Dependencies first. A package that failed keeps back the packages that require it: they would require
     * a version that is not there.
     *
     * @param list<Release> $releases
     * @return list<string> the package and the tag of every release that failed
     */
    private static function publish(SymfonyStyle $io, Git $git, Remote $remote, Api $api, array $releases): array
    {
        $publisher = new Publisher($git, $remote, $api);
        $failed = [];

        foreach ($releases as $release) {
            $failedDependencies = array_intersect($release->requires, array_keys($failed));

            if ($failedDependencies !== []) {
                $io->writeln(\sprintf(
                    '%s: not released: it requires %s, whose release failed.',
                    $release->package,
                    implode(', ', array_map(self::name(...), $failedDependencies)),
                ), OutputInterface::OUTPUT_RAW);
                $failed[$release->package] = $release->package . ' ' . $release->tag;

                continue;
            }

            try {
                foreach ($publisher->publish($release) as $step) {
                    $io->writeln(\sprintf('%s: %s', $release->package, $step), OutputInterface::OUTPUT_RAW);
                }
            } catch (MonorepoException $exception) {
                $error = \sprintf('%s: %s', $release->package, $exception->getMessage());
                $io->writeln($error, OutputInterface::OUTPUT_RAW);
                $failed[$release->package] = $release->package . ' ' . $release->tag;
            }
        }

        return array_values($failed);
    }

    private static function name(string $package): string
    {
        return Monorepo::VENDOR . '/' . $package;
    }

    /**
     * @return non-empty-string
     */
    private static function commit(Git $git, string $revision): string
    {
        $resolved = $git->result(['rev-parse', '--verify', '--quiet', '--end-of-options', $revision . '^{commit}']);
        $commit = trim($resolved->stdout);

        if ($commit === '') {
            throw new MonorepoException(\sprintf('%s is not a commit of this repository.', $revision));
        }

        return $commit;
    }
}
