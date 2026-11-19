<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Internal\Generator;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use function Thesis\exceptionally;

/**
 * @internal
 */
#[AsCommand('sync', 'Regenerate every derived file')]
final readonly class SyncCommand
{
    public function __construct(
        private string $root,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $monorepo = Monorepo::load($this->root);
            $files = Generator::generate($monorepo);
        } catch (MonorepoException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        foreach (Generator::stale($monorepo, $files) as $path => $content) {
            try {
                exceptionally(function () use ($path, $content) {
                    $file = $this->root . '/' . $path;

                    // A generated file may be the first of its directory (.github/ISSUE_TEMPLATE/).
                    if (!is_dir(\dirname($file))) {
                        mkdir(\dirname($file), recursive: true);
                    }

                    file_put_contents($file, $content);
                });
            } catch (\ErrorException $error) {
                $io->error(\sprintf('Cannot write %s: %s.', $path, $error->getMessage()));

                return Command::FAILURE;
            }

            $io->writeln('Updated ' . $path);
        }

        $io->success(\sprintf('%d package(s) are in sync.', \count($monorepo->packages)));

        return Command::SUCCESS;
    }
}
