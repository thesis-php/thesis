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
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Release\Plan;
use Thesis\Monorepo\Internal\Release\Publisher;
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

        if ($plan->errors !== []) {
            // Raw and unwrapped: one reason per line, with paths and versions that must stay whole.
            $io->writeln(implode("\n", $plan->errors), OutputInterface::OUTPUT_RAW);
            $io->error('Nothing is released. The next push of main that passes the checks releases what it fixes.');

            return Command::FAILURE;
        }

        if ($plan->releases === []) {
            $io->writeln('Nothing to release.');

            return Command::SUCCESS;
        }

        foreach ($plan->releases as $release) {
            $io->writeln(\sprintf('%s: %s at %s', $release->package, $release->tag, $release->commit));
        }

        if (!$this->push) {
            $io->writeln('Set SPLIT_REMOTE to release.');

            return Command::SUCCESS;
        }

        $publisher = new Publisher($git, $remote, $api);

        // Dependencies first, and nothing after a failure: a dependent would require a version that is not there.
        foreach ($plan->releases as $release) {
            try {
                foreach ($publisher->publish($release) as $step) {
                    $io->writeln(\sprintf('%s: %s', $release->package, $step), OutputInterface::OUTPUT_RAW);
                }
            } catch (MonorepoException $exception) {
                $error = \sprintf('%s: %s', $release->package, $exception->getMessage());
                $io->writeln($error, OutputInterface::OUTPUT_RAW);
                $io->error(\sprintf(
                    'The release stopped at %s %s. Re-run the job, or the next push of main continues it.',
                    $release->package,
                    $release->tag,
                ));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
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
