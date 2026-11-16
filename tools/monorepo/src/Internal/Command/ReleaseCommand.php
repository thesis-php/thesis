<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\GitHub\Checks;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Release\Plan;
use Thesis\Monorepo\Internal\Release\Publisher;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Internal\Split\Split;

/**
 * @internal
 */
#[AsCommand('release', 'Release the changelog headings that a push of main added, or those of the given packages')]
final readonly class ReleaseCommand
{
    /**
     * @param string $remote where the split repositories are, with the credentials to push when $push
     * @param bool $push false to only print what would be released
     * @param ?Api $api GITHUB_TOKEN of the app: the split repositories and their releases
     * @param ?Api $checks a token that reads the runs of the checks of the monorepo, for a dispatch
     */
    public function __construct(
        private string $root,
        private string $remote,
        private bool $push,
        private ?Api $api,
        private ?Api $checks,
    ) {}

    /**
     * @param list<string> $packages
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A commit of main to release')]
        string $commit,
        #[Argument('Release the version under [Unreleased] of these packages, instead of --before')]
        array $packages = [],
        #[Option('The head of main before the push: the push releases the changelog headings it added')]
        ?string $before = null,
    ): int {
        $git = new Git($this->root);

        try {
            $remote = Remote::parse($this->remote);
            $api = $this->api ?? throw new MonorepoException('Set GITHUB_TOKEN: it reads the split repositories.');

            if (($before === null) === ($packages === [])) {
                throw new MonorepoException('Give either --before (a push) or the packages to release (a dispatch).');
            }

            $commit = self::commit($git, $commit);

            if (!$git->succeeds(['merge-base', '--is-ancestor', $commit, Split::REF])) {
                throw new MonorepoException(\sprintf('%s is not reachable from %s.', $commit, Split::REF));
            }

            if ($before === null) {
                $checks = $this->checks ?? throw new MonorepoException(
                    'Set CHECKS_TOKEN: a dispatch releases only a commit whose checks passed.',
                );

                if (!Checks::passed($checks, $commit)) {
                    throw new MonorepoException(\sprintf(
                        'No run of %s passed all its checks for %s: a dispatch releases only a commit that passed.',
                        Checks::WORKFLOW,
                        $commit,
                    ));
                }
            }

            $releases = Plan::of(
                git: $git,
                api: $api,
                remote: $remote,
                commit: $commit,
                before: $before === null ? null : self::commit($git, $before),
                packages: $packages,
            );
        } catch (MonorepoException $exception) {
            // Raw and unwrapped: one reason per line, with paths and versions that must stay whole.
            $io->writeln($exception->getMessage(), OutputInterface::OUTPUT_RAW);
            $io->error('Nothing is released.');

            return Command::FAILURE;
        }

        if ($releases === []) {
            $io->writeln('Nothing to release.');

            return Command::SUCCESS;
        }

        foreach ($releases as $release) {
            $io->writeln(\sprintf('%s: %s at %s', $release->package, $release->tag, $release->commit));
        }

        if (!$this->push) {
            $io->writeln('Set SPLIT_REMOTE to release.');

            return Command::SUCCESS;
        }

        $publisher = new Publisher($git, $remote, $api);

        // Dependencies first, and none after a failure: a dependent would require a version that is not there.
        foreach ($releases as $release) {
            try {
                foreach ($publisher->publish($release) as $step) {
                    $io->writeln(\sprintf('%s: %s', $release->package, $step), OutputInterface::OUTPUT_RAW);
                }
            } catch (MonorepoException $exception) {
                $error = \sprintf('%s: %s', $release->package, $exception->getMessage());
                $io->writeln($error, OutputInterface::OUTPUT_RAW);
                $io->error(\sprintf(
                    'The release of %s %s failed. Re-run the job or dispatch the release of the same commit.',
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
