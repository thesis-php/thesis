<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Internal\GitHub\HttpApi;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;

/**
 * @internal
 */
#[AsCommand('github', 'Bring the repository of every package to the state of its manifest')]
final readonly class GitHubCommand
{
    public function __construct(
        private string $root,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Print the changes without making them')]
        bool $dryRun = false,
    ): int {
        $token = getenv('GITHUB_TOKEN');

        if ($token === false || $token === '') {
            $io->error('Set GITHUB_TOKEN, e.g. GITHUB_TOKEN=$(gh auth token) make github ARGS=--dry-run.');

            return Command::FAILURE;
        }

        try {
            $monorepo = Monorepo::load($this->root);
            $changes = Repositories::sync($monorepo, new HttpApi($token), $dryRun);
        } catch (MonorepoException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->listing($changes);

        if ($dryRun && $changes !== []) {
            $io->warning(\sprintf('Dry run: %d change(s) not made.', \count($changes)));
        } else {
            $io->success(\sprintf('%d repositories are in sync.', \count($monorepo->packages)));
        }

        return Command::SUCCESS;
    }
}
