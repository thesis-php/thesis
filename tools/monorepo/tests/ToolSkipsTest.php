<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\ToolSkips;

#[Test]
final readonly class ToolSkipsTest
{
    public static function takesOptionalDependenciesAndSkipsFromManifests(): void
    {
        $fixture = new Fixture()
            ->file('packages/async/src/Semaphore.php', "<?php\n\nfinal class Semaphore {}\n")
            ->file('packages/bytes/polyfill/Bytes.php', "<?php\n")
            ->package('async', <<<'PHP'
                optional: ['amphp/amp', 'ext-pcntl'],
                skip: [
                    new Thesis\Monorepo\RectorSkip(
                        rule: 'Rector\Promotion',
                        paths: ['src/Semaphore.php'],
                        reason: 'Types.',
                    ),
                ],
                PHP)
            ->package('bytes', <<<'PHP'
                skip: [
                    new Thesis\Monorepo\FixerSkip(paths: ['polyfill/Bytes.php'], reason: 'Declared so.'),
                    new Thesis\Monorepo\RectorSkip(rule: 'Rector\Promotion', paths: ['polyfill'], reason: 'Polyfill.'),
                ],
                PHP)
        ;

        $skips = ToolSkips::of($fixture->load());

        Assert::same($skips->optional, [$fixture->root . '/packages/async/src' => ['amphp/amp', 'ext-pcntl']]);
        Assert::same($skips->fixer, [$fixture->root . '/packages/bytes/polyfill/Bytes.php']);
        Assert::same($skips->rector, [
            'Rector\Promotion' => [
                $fixture->root . '/packages/async/src/Semaphore.php',
                $fixture->root . '/packages/bytes/polyfill',
            ],
        ]);
    }

    public static function isEmptyWithoutSkips(): void
    {
        $fixture = new Fixture()->package('bytes');

        $skips = ToolSkips::of($fixture->load());

        Assert::same([$skips->optional, $skips->fixer, $skips->rector], [[], [], []]);
    }

    public static function failsOnEverySkippedPathThatDoesNotExist(): void
    {
        $fixture = new Fixture()->package('bytes', <<<'PHP'
            skip: [
                new Thesis\Monorepo\FixerSkip(paths: ['src/Gone.php'], reason: 'Gone.'),
                new Thesis\Monorepo\RectorSkip(rule: 'Rector\Promotion', paths: ['src/Lost.php'], reason: 'Lost.'),
            ],
            PHP);

        Expect::exception(MonorepoException::class)->withMessage(
            "packages/bytes/package.php: the skipped path src/Gone.php does not exist, fix or remove the skip.\n"
            . 'packages/bytes/package.php: the skipped path src/Lost.php does not exist, fix or remove the skip.',
        );

        ToolSkips::of($fixture->load());
    }
}
