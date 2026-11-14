<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Generator;
use Thesis\Monorepo\Monorepo;
use Thesis\Monorepo\Validator;

#[AsCommand('sync', 'Validate the packages and regenerate every derived file')]
final readonly class SyncCommand
{
    public function __construct(
        private string $root,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $monorepo = Monorepo::load($this->root);
        $errors = Validator::validate($monorepo);

        if ($errors !== []) {
            return Report::errors($io, $errors, \count($monorepo->packages));
        }

        $files = Generator::generate($monorepo);

        foreach (Generator::stale($monorepo, $files) as $path => $content) {
            file_put_contents($this->root . '/' . $path, $content);
            $io->writeln('Updated ' . $path);
        }

        return Report::errors($io, [], \count($monorepo->packages));
    }
}
