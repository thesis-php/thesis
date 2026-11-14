<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Generator;
use Thesis\Monorepo\Monorepo;
use Thesis\Monorepo\Validator;

#[AsCommand('check', 'Validate the packages and fail if a derived file is stale')]
final readonly class CheckCommand
{
    public function __construct(
        private string $root,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $monorepo = Monorepo::load($this->root);

        return Report::errors($io, [
            ...Validator::validate($monorepo),
            ...array_map(
                static fn(string $path): string => \sprintf('%s is stale, run `make sync`.', $path),
                array_keys(Generator::stale($monorepo, Generator::generate($monorepo))),
            ),
        ], \count($monorepo->packages));
    }
}
