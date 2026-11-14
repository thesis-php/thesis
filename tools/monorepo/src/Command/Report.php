<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final readonly class Report
{
    /**
     * @param list<string> $errors
     */
    public static function errors(SymfonyStyle $io, array $errors, int $packages): int
    {
        if ($errors !== []) {
            $io->listing($errors);
            $io->error(\sprintf('%d problem(s).', \count($errors)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d package(s) are consistent.', $packages));

        return Command::SUCCESS;
    }
}
