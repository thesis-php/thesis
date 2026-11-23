<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use Testo\Application\Config\SuiteConfig;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Tools;
use Thesis\TestoPlugin\PhpErrorsPlugin;

#[Test]
final readonly class ToolsTest
{
    public static function takesSkipsFromManifests(): void
    {
        $fixture = new Fixture()
            ->file('packages/async/src/Semaphore.php', "<?php\n\nfinal class Semaphore {}\n")
            ->file('packages/bytes/polyfill/Bytes.php', "<?php\n")
            ->package('async', <<<'PHP'
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

        $tools = Tools::of($fixture->load());

        Assert::same($tools->fixerSkips, [$fixture->root . '/packages/bytes/polyfill/Bytes.php']);
        Assert::same($tools->rectorSkips, [
            'Rector\Promotion' => [
                $fixture->root . '/packages/async/src/Semaphore.php',
                $fixture->root . '/packages/bytes/polyfill',
            ],
        ]);
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

        Tools::of($fixture->load());
    }

    public static function allowsOptionalDependenciesInSrcOfTheirPackage(): void
    {
        $fixture = new Fixture()
            ->file('packages/async/src/Bridge.php', "<?php\n")
            ->file('packages/bytes/src/Bytes.php', "<?php\n")
            ->package('async', <<<'PHP'
                requireDev: ['amphp/amp' => '^3.0', 'ext-filter' => '*', 'ext-pcntl' => '*'],
                optional: ['amphp/amp', 'ext-filter', 'ext-pcntl'],
                PHP)
            ->package('bytes', "require: ['ext-filter' => '*']")
        ;

        $ignores = Tools::of($fixture->load())->ignoreOptionalDependencies(new Configuration())->getIgnoreList();
        $ignored = static fn(string $file, string $dependency) => $ignores->shouldIgnoreError(
            errorType: ErrorType::DEV_DEPENDENCY_IN_PROD,
            realPath: $fixture->root . '/' . $file,
            dependency: $dependency,
        );

        Assert::same(
            actual: [
                $ignored('packages/async/src/Bridge.php', 'amphp/amp'),
                $ignored('packages/async/src/Bridge.php', 'ext-pcntl'),
                $ignored('packages/async/src/Bridge.php', 'ext-filter'),
                $ignored('packages/async/src/Bridge.php', 'psr/clock'),
                $ignored('packages/bytes/src/Bytes.php', 'amphp/amp'),
            ],
            expected: [true, true, false, false, false],
        );
    }

    public static function hasTestoSrcOfEveryPackage(): void
    {
        $fixture = new Fixture()->package('async')->package('bytes');

        Assert::same(Tools::of($fixture->load())->testoSrc, [
            $fixture->root . '/packages/async/src',
            $fixture->root . '/packages/bytes/src',
        ]);
    }

    public static function hasTestoSuiteOfEveryToolAndPackageWithTests(): void
    {
        $fixture = new Fixture()
            ->file('tools/lint/tests/LintTest.php', "<?php\n")
            ->file('tools/build/tests/BuildTest.php', "<?php\n")
            ->file('tools/docs/src/Docs.php', "<?php\n")
            ->file('packages/async/tests/AsyncTest.php', "<?php\n")
            ->package('async', 'testoPlugins: [new Thesis\TestoPlugin\PhpErrorsPlugin()]')
            ->package('stan')
        ;

        $suites = array_map(
            static fn(SuiteConfig $suite) => [
                $suite->name,
                array_map(strval(...), $suite->location->includes),
                array_map(get_class(...), [...$suite->plugins]),
            ],
            Tools::of($fixture->load())->testoSuites,
        );

        Assert::same($suites, [
            ['build', [$fixture->root . '/tools/build/tests'], []],
            ['lint', [$fixture->root . '/tools/lint/tests'], []],
            ['async', [$fixture->root . '/packages/async/tests'], [PhpErrorsPlugin::class]],
        ]);
    }
}
