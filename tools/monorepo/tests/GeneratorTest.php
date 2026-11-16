<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\Generator;
use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\MonorepoException;

#[Test]
final readonly class GeneratorTest
{
    public static function generatesPackageComposerJson(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert::same(Json::decode($files['packages/async/composer.json'] ?? '', 'async'), [
            'name' => 'thesis/async',
            'description' => 'Package async.',
            'license' => 'MIT',
            'type' => 'library',
            'require' => ['php' => '^8.5', 'revolt/event-loop' => '^1.0', 'thesis/bytes' => '^0.1'],
            'require-dev' => ['amphp/amp' => '^3.0', 'psr/clock' => '^1.1'],
            'autoload' => ['psr-4' => ['Thesis\Async\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Thesis\Async\\' => 'tests/']],
            'extra' => ['branch-alias' => ['dev-main' => '0.1.x-dev']],
        ]);
    }

    public static function requiresMonorepoPackageByRangeOfItsSeries(): void
    {
        $fixture = new Fixture()
            ->package('time', version: '0.3')
            ->package('net', version: '2.1')
            ->package('amqp', "require: ['thesis/net' => '*'], requireDev: ['thesis/time' => '*']", version: '2.4')
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/amqp/composer.json'] ?? '', 'amqp');
        Assert::same($composer['require'] ?? null, ['php' => '^8.5', 'thesis/net' => '^2.0']);
        Assert::same($composer['require-dev'] ?? null, ['thesis/time' => '^0.3']);
        Assert::same($composer['extra'] ?? null, ['branch-alias' => ['dev-main' => '2.4.x-dev']]);
        $root = Json::decode($files['composer.json'] ?? '', 'root');
        Assert::same(
            actual: $root['replace'] ?? null,
            expected: ['thesis/amqp' => '2.4.x-dev', 'thesis/net' => '2.1.x-dev', 'thesis/time' => '0.3.x-dev'],
        );
    }

    #[DataSet(['^0.3.5'])]
    #[DataSet(['~0.3.5'])]
    #[DataSet(['>=0.3.2 <0.4'])]
    public static function keepsConstraintThatRaisesLowerBoundWithinRange(string $constraint): void
    {
        $fixture = new Fixture()
            ->package('time', version: '0.3')
            ->package('async', "require: ['thesis/time' => '{$constraint}']")
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/async/composer.json'] ?? '', 'async');
        Assert::same($composer['require'] ?? null, ['php' => '^8.5', 'thesis/time' => $constraint]);
    }

    #[DataSet(['^0.2'])]
    #[DataSet(['^0.3 || ^0.4'])]
    #[DataSet(['>=0.3'])]
    #[DataSet(['one'])]
    public static function failsOnConstraintOutsideRange(string $constraint): void
    {
        $fixture = new Fixture()
            ->package('time', version: '0.3')
            ->package('async', "require: ['thesis/time' => '{$constraint}']")
        ;

        Expect::exception(MonorepoException::class)->withMessage(\sprintf(
            "packages/async/package.php: thesis/time is required as %s, outside ^0.3 of its series 0.3: require it as '*' or within ^0.3.",
            $constraint,
        ));

        Generator::generate($fixture->load());
    }

    #[DataSet(['0.1.0'])]
    #[DataSet(['1'])]
    #[DataSet(['v0.1'])]
    #[DataSet(['01.0'])]
    public static function failsOnVersionThatIsNotSeries(string $version): void
    {
        $fixture = new Fixture()->package('time', version: $version);

        Expect::exception(MonorepoException::class)->withMessage(\sprintf(
            "packages/time/package.php: the version %s is not a series X.Y, e.g. version: '0.1'.",
            $version,
        ));

        Generator::generate($fixture->load());
    }

    public static function failsOnBranchAliasInExtra(): void
    {
        $fixture = new Fixture()->package('time', "extra: ['branch-alias' => ['dev-main' => '1.0.x-dev']]");

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/time/package.php: extra.branch-alias is generated from the version, remove it.',
        );

        Generator::generate($fixture->load());
    }

    public static function mergesPackagesIntoRootComposerJson(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert::same(Json::decode($files['composer.json'] ?? '', 'root'), [
            'name' => 'thesis/monorepo',
            'description' => 'Fixture.',
            'license' => 'MIT',
            'type' => 'project',
            'require' => ['php' => '^8.5', 'ext-bcmath' => '*', 'psr/clock' => '^1.1', 'revolt/event-loop' => '^1.0'],
            'require-dev' => ['amphp/amp' => '^3.0'],
            'replace' => ['thesis/async' => '0.1.x-dev', 'thesis/bytes' => '0.1.x-dev'],
            'autoload' => [
                'psr-4' => ['Thesis\Async\\' => 'packages/async/src/', 'Thesis\Bytes\\' => 'packages/bytes/src/'],
            ],
            'autoload-dev' => [
                'psr-4' => ['Thesis\Async\\' => 'packages/async/tests/', 'Thesis\Bytes\\' => 'packages/bytes/tests/'],
            ],
        ]);
    }

    public static function requiresPlatformOfConfigInEveryComposerJson(): void
    {
        $fixture = new Fixture()
            ->file('monorepo.php', <<<'PHP'
                <?php

                return new Thesis\Monorepo\Config(
                    php: '^8.5',
                    description: 'Fixture.',
                    copyrightHolder: 'Fixture',
                    licenseSince: 2026,
                    platform: ['php-64bit' => '*'],
                );
                PHP)
            ->package('bytes', "require: ['ext-bcmath' => '*']")
        ;

        $files = Generator::generate($fixture->load());

        $require = static fn(string $path) => Json::decode($files[$path] ?? '', $path)['require'] ?? null;
        $expected = ['php' => '^8.5', 'php-64bit' => '*', 'ext-bcmath' => '*'];
        Assert::same($require('packages/bytes/composer.json'), $expected);
        Assert::same($require('composer.json'), $expected);
    }

    public static function writesCatalogBetweenMarkers(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['AGENTS.md'] ?? '')
            ->contains("- `bytes`: Package bytes.\n")
            ->contains("- `async`: Package async. Requires: bytes.\n")
        ;
    }

    public static function writesLicenseFromTheYearOfManifest(): void
    {
        $fixture = new Fixture()->package('bytes', licenseSince: 2024);

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['packages/bytes/LICENSE'] ?? '')
            ->startsWith("MIT License\n\nCopyright (c) 2024-present Fixture\n")
        ;
    }

    public static function keepsOnlyWhatComposerNeedsInDistOfPackage(): void
    {
        $fixture = new Fixture()
            ->file('packages/bytes/polyfill/Bytes.php', "<?php\n")
            ->file('packages/bytes/skills/bytes/SKILL.md', "# Bytes\n")
            ->package('bytes', "dist: ['polyfill']")
        ;

        $files = Generator::generate($fixture->load());

        Assert::same($files['packages/bytes/.gitattributes'] ?? '', <<<'TXT'
            # Generated by bin/monorepo sync, do not edit.
            # The split repository is a copy of the package, the archive that composer installs has only these.
            /* export-ignore
            /LICENSE -export-ignore
            /README.md -export-ignore
            /composer.json -export-ignore
            /polyfill -export-ignore
            /skills -export-ignore
            /src -export-ignore

            TXT);
    }

    public static function failsOnDistPathThatDoesNotExist(): void
    {
        $fixture = new Fixture()->package('bytes', "dist: ['polyfill']");

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/bytes/package.php: the dist path polyfill does not exist, fix or remove it.',
        );

        Generator::generate($fixture->load());
    }

    public static function autoloadsFilesWithoutClassLikeDeclarations(): void
    {
        $fixture = self
            ::fixture()
            ->file('packages/async/src/functions.php', "<?php\n\nfunction delay(): void {}\n")
            ->file('packages/async/src/Internal/stdio.php', "<?php\n\nconst STDIO = new \\stdClass();\n")
            ->file('packages/async/src/platform.php', "<?php\n\nif (PHP_OS_FAMILY === 'Windows') {}\n")
            ->file('packages/async/src/anonymous.php', "<?php\n\nfunction any(): object { return new class {}; }\n")
            ->file('packages/async/src/name.php', "<?php\n\nfunction name(): string { return Task::class; }\n")
            ->file('packages/async/src/Task.php', "<?php\n\nfinal class Task {}\n")
            ->file('packages/async/src/Awaitable.php', "<?php\n\ninterface Awaitable {}\n")
            ->file('packages/async/src/Awaits.php', "<?php\n\ntrait Awaits {}\n")
            ->file('packages/async/src/State.php', "<?php\n\nenum State {}\n")
            ->file('packages/async/src/README.md', "# Not PHP\n")
            ->file('packages/async/tests/functions.php', "<?php\n\nfunction fixture(): void {}\n")
            ->file('packages/async/codegen/generate.php', "<?php\n\nfunction generate(): void {}\n")
            ->package('async', "devFiles: ['codegen/generate.php']")
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/async/composer.json'] ?? '', 'async');
        Assert::same($composer['autoload'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'src/'],
            'files' => [
                'src/Internal/stdio.php',
                'src/anonymous.php',
                'src/functions.php',
                'src/name.php',
                'src/platform.php',
            ],
        ]);
        Assert::same($composer['autoload-dev'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'tests/'],
            'files' => ['tests/functions.php', 'codegen/generate.php'],
        ]);
        $root = Json::decode($files['composer.json'] ?? '', 'root');
        Assert::same($root['autoload-dev'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'packages/async/tests/', 'Thesis\Bytes\\' => 'packages/bytes/tests/'],
            'files' => ['packages/async/tests/functions.php', 'packages/async/codegen/generate.php'],
        ]);
    }

    #[DataSet(['files', 'src/functions.php'])]
    #[DataSet(['devFiles', 'tests/functions.php'])]
    public static function failsOnAutoloadFileOfManifestInSrcOrTests(string $argument, string $path): void
    {
        $fixture = new Fixture()->package('async', "{$argument}: ['{$path}']");

        Expect::exception(MonorepoException::class)->withMessage(\sprintf(
            'packages/async/package.php: %s is autoloaded as a file without classes in src/ or tests/, remove it from %s.',
            $path,
            $argument,
        ));

        Generator::generate($fixture->load());
    }

    public static function writesTypeAndExtraOfManifest(): void
    {
        $fixture = new Fixture()->package('stan', <<<'PHP'
            type: 'phpstan-extension',
            extra: ['phpstan' => ['includes' => ['extension.neon']]],
            PHP);

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/stan/composer.json'] ?? '', 'stan');
        Assert::same($composer['type'] ?? null, 'phpstan-extension');
        Assert::same($composer['extra'] ?? null, [
            'phpstan' => ['includes' => ['extension.neon']],
            'branch-alias' => ['dev-main' => '0.1.x-dev'],
        ]);
    }

    public static function writesTestoSuitesWithPlugins(): void
    {
        $fixture = self::fixture()->file('packages/async/testo.plugins.php', "<?php\n\nreturn [];\n");

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['testo.php'] ?? '')
            ->contains(<<<'PHP'
                        new SuiteConfig(
                            name: 'async',
                            location: [__DIR__ . '/packages/async/tests'],
                            plugins: require __DIR__ . '/packages/async/testo.plugins.php',
                        ),
                        new SuiteConfig(name: 'bytes', location: [__DIR__ . '/packages/bytes/tests']),

                PHP)
        ;
    }

    public static function writesNoTestsOfPackageWithoutTests(): void
    {
        $fixture = self::fixture()->package('stan');

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['testo.php'] ?? '')
            ->contains("        __DIR__ . '/packages/stan/src',\n")
            ->notContains("name: 'stan'")
        ;
        $package = Json::decode($files['packages/stan/composer.json'] ?? '', 'stan');
        Assert::false(isset($package['autoload-dev']));
        $root = Json::decode($files['composer.json'] ?? '', 'root');
        Assert::same(
            actual: $root['autoload-dev'] ?? null,
            expected: [
                'psr-4' => ['Thesis\Async\\' => 'packages/async/tests/', 'Thesis\Bytes\\' => 'packages/bytes/tests/'],
            ],
        );
    }

    public static function writesTestoSuiteOfEveryToolWithTests(): void
    {
        $fixture = self
            ::fixture()
            ->file('tools/lint/tests/LintTest.php', "<?php\n")
            ->file('tools/build/tests/BuildTest.php', "<?php\n")
            ->file('tools/docs/src/Docs.php', "<?php\n")
        ;

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['testo.php'] ?? '')
            ->contains(<<<'PHP'
                    suites: [
                        new SuiteConfig(name: 'build', location: [__DIR__ . '/tools/build/tests']),
                        new SuiteConfig(name: 'lint', location: [__DIR__ . '/tools/lint/tests']),
                        new SuiteConfig(name: 'async', location: [__DIR__ . '/packages/async/tests']),
                PHP)
            ->notContains('docs')
        ;
    }

    public static function writesOptionalDependenciesAndSkipsForTools(): void
    {
        $fixture = self
            ::fixture()
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

        $files = Generator::generate($fixture->load());

        Assert::same($files[Generator::TOOLS] ?? '', <<<'PHP'
            <?php

            declare(strict_types=1);

            // Generated by bin/monorepo sync from optional and skip of packages/*/package.php, do not edit.
            // Paths are relative to the root.

            return [
                'optional' => [
                    'packages/async/src' => ['amphp/amp', 'ext-pcntl'],
                ],
                'fixer' => [
                    // Declared so.
                    'packages/bytes/polyfill/Bytes.php',
                ],
                'rector' => [
                    \Rector\Promotion::class => [
                        // Types.
                        'packages/async/src/Semaphore.php',
                        // Polyfill.
                        'packages/bytes/polyfill',
                    ],
                ],
            ];

            PHP);
    }

    public static function writesEmptyListsForToolsWithoutSkips(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files[Generator::TOOLS] ?? '')
            ->contains("    'optional' => [],\n    'fixer' => [],\n    'rector' => [],\n")
        ;
    }

    public static function failsOnSkippedPathThatDoesNotExist(): void
    {
        $fixture = self::fixture()->package('bytes', <<<'PHP'
            skip: [new Thesis\Monorepo\FixerSkip(paths: ['src/Gone.php'], reason: 'Gone.')],
            PHP);

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/bytes/package.php: the skipped path src/Gone.php does not exist, fix or remove the skip.',
        );

        Generator::generate($fixture->load());
    }

    public static function reportsOnlyChangedFilesAsStale(): void
    {
        $fixture = self::fixture();
        $monorepo = $fixture->load();
        $files = Generator::generate($monorepo);
        foreach ($files as $path => $content) {
            $fixture->file($path, $content);
        }

        $fixture->file('packages/bytes/composer.json', "{}\n");

        Assert::same(array_keys(Generator::stale($monorepo, $files)), ['packages/bytes/composer.json']);
    }

    private static function fixture(): Fixture
    {
        return new Fixture()
            ->file('packages/async/tests/Internal/ScopeTest.php', "<?php\n\nfinal class ScopeTest {}\n")
            ->file('packages/bytes/tests/BytesTest.php', "<?php\n\nfinal class BytesTest {}\n")
            ->package('bytes', "require: ['ext-bcmath' => '*', 'psr/clock' => '^1.0']")
            ->package('async', <<<'PHP'
                require: ['revolt/event-loop' => '^1.0', 'thesis/bytes' => '*'],
                requireDev: ['amphp/amp' => '^3.0', 'psr/clock' => '^1.1'],
                PHP)
        ;
    }
}
