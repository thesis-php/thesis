<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Command\SyncCommand;
use Thesis\Monorepo\Internal\Generator;

#[Test]
final readonly class SyncCommandTest
{
    public static function writesStaleFiles(): void
    {
        $fixture = new Fixture()->package('a');
        $license = Generator::generate($fixture->load())['packages/a/LICENSE'] ?? '';

        [$code] = self::run($fixture);

        Assert::same($code, Command::SUCCESS);
        Assert::same(file_get_contents($fixture->root . '/packages/a/LICENSE'), $license);
    }

    public static function failsWithReasonWhenFileCannotBeWritten(): void
    {
        $fixture = new Fixture()->package('a');
        mkdir($fixture->root . '/packages/a/LICENSE');

        [$code, $output] = self::run($fixture);

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains('packages/a/LICENSE')->contains('Is a directory');
    }

    /**
     * @return array{int, string} the exit code and the output, its lines joined: SymfonyStyle wraps an error
     */
    private static function run(Fixture $fixture): array
    {
        $application = new Application();
        $application->addCommand(new SyncCommand($fixture->root));
        $tester = new CommandTester($application->find('sync'));
        $code = $tester->execute([], ['decorated' => false]);

        return [$code, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? ''];
    }
}
