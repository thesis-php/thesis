<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thesis\Monorepo\Layer;
use Thesis\Monorepo\Scaffolder;

#[AsCommand('new', 'Scaffold a package in packages/<name>/')]
final readonly class NewPackageCommand
{
    public function __construct(
        private string $root,
        private SyncCommand $sync,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Directory name in kebab-case, the package becomes thesis/<name>')]
        string $name,
        #[Argument('One of: primitive, runtime, io, protocol, application')]
        string $layer,
        #[Argument('One sentence, it goes to composer.json and to the package catalog')]
        string $description,
    ): int {
        $layerCase = Layer::tryFrom($layer);

        if ($layerCase === null) {
            $io->error(\sprintf('Unknown layer "%s", expected one of: %s.', $layer, implode(', ', Layer::values())));

            return 1;
        }

        $path = Scaffolder::create($this->root, $name, $layerCase, $description);
        $io->success(\sprintf('Created %s. Fill AGENTS.md as the package takes shape.', $path));

        return ($this->sync)($io);
    }
}
