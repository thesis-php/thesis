<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Internal\Generator;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\Validator;

/**
 * @internal
 */
#[AsCommand('check', 'Validate the packages and fail if a derived file is stale')]
final readonly class CheckCommand
{
    public function __construct(
        private string $root,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $monorepo = Monorepo::load($this->root);
        $stale = array_keys(Generator::stale($monorepo, Generator::generate($monorepo)));

        return Report::errors(
            io: $io,
            errors: [
                ...Validator::validate($monorepo),
                ...array_map(static fn(string $path) => \sprintf('%s is stale, run `make sync`.', $path), $stale),
            ],
            packages: \count($monorepo->packages),
        );
    }
}
