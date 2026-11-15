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
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Internal\Split\Split;

/**
 * @internal
 */
#[AsCommand('split', 'Split packages/* of a branch or a v* tag, push them to thesis-php/<name> with SPLIT_REMOTE')]
final readonly class SplitCommand
{
    /**
     * @param ?string $remote SPLIT_REMOTE, null to print the splits without pushing them
     * @param ?Api $api GITHUB_TOKEN, required for a push: it tells which repositories are splits
     */
    public function __construct(
        private string $root,
        private ?string $remote,
        private ?Api $api,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A branch or a v* tag, e.g. refs/heads/main')]
        string $ref,
    ): int {
        $git = new Git($this->root);

        try {
            $remote = $this->remote === null ? null : Remote::parse($this->remote);
            $split = Split::of($git, $ref);
        } catch (MonorepoException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($split->unchanged as $package) {
            $io->writeln(\sprintf('%s: unchanged since %s, skipped', $package, $split->since ?? ''));
        }

        if ($remote === null) {
            foreach ($split->commits as $package => $commit) {
                $io->writeln(\sprintf('%s: %s', $package, $commit));
            }

            return Command::SUCCESS;
        }

        if ($this->api === null) {
            $io->error('Set GITHUB_TOKEN to push: only the repositories that are splits get a push.');

            return Command::FAILURE;
        }

        $pushes = [];
        $failed = [];

        foreach ($split->commits as $package => $commit) {
            try {
                $isSplit = Repositories::isSplit($this->api, $package);
            } catch (MonorepoException $exception) {
                $io->writeln(\sprintf('%s: %s', $package, $exception->getMessage()), OutputInterface::OUTPUT_RAW);
                $failed[] = $package;

                continue;
            }

            if (!$isSplit) {
                $io->writeln(\sprintf(
                    '%1$s: %2$s/%1$s is not a split of %3$s/%1$s: it is missing, has no rulesets of the split'
                        . ' or redirects to a renamed repository. Nothing is pushed there.',
                    $package,
                    Repositories::ORGANIZATION,
                    Monorepo::PACKAGES_DIR,
                ));
                $failed[] = $package;

                continue;
            }

            // Never forced: a split that cannot fast-forward fails instead of rewriting a published history.
            $pushes[$package] = ['push', $remote->url($package), $commit . ':' . $split->ref];
        }

        foreach ($git->with($remote->env)->runAll($pushes) as $package => $result) {
            // Raw: the output of git is not markup of the console.
            $io->writeln(\sprintf('%s: %s', $package, trim($result->stderr)), OutputInterface::OUTPUT_RAW);

            if ($result->code !== 0) {
                $failed[] = $package;
            }
        }

        if ($failed !== []) {
            $io->error(\sprintf('The push of %s failed.', implode(', ', $failed)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
