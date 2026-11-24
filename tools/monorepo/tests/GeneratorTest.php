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
            'support' => [
                'issues' => 'https://github.com/thesis-php/thesis/issues',
                'source' => 'https://github.com/thesis-php/thesis/tree/main/packages/async',
                'security' => 'https://github.com/thesis-php/thesis/security/policy',
            ],
            'require' => ['php' => '^8.5', 'psr/log' => '^3.0', 'thesis/bytes' => '^0.1'],
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

    public static function pinsTheProjectsRuntimeDependencyTreeToDevMain(): void
    {
        $fixture = new Fixture()
            ->package('time')
            ->package('io', "require: ['thesis/time' => '*']")
            ->package('net', "require: ['thesis/io' => '*']")
            ->package('other')
            ->package('async', "require: ['thesis/net' => '*'], requireDev: ['thesis/other' => '*']")
            ->package('demo', <<<'PHP'
                require: ['psr/log' => '^3.0', 'thesis/async' => '*', 'thesis/io' => '*'],
                type: 'project',
                devMain: true,
                PHP)
        ;

        $files = Generator::generate($fixture->load());

        $project = Json::decode($files['packages/demo/composer.json'] ?? '', 'demo');
        Assert::same($project['type'] ?? null, 'project');
        Assert::same($project['require'] ?? null, [
            'php' => '^8.5',
            'psr/log' => '^3.0',
            'thesis/async' => 'dev-main',
            'thesis/io' => 'dev-main',
            'thesis/net' => 'dev-main',
            'thesis/time' => 'dev-main',
        ]);
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
            'require' => ['php' => '^8.5', 'ext-bcmath' => '*', 'psr/clock' => '^1.1', 'psr/log' => '^3.0'],
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

    public static function mapsRootNamespaceToSrcAndTestsOfPackagesWithRootEntry(): void
    {
        $fixture = new Fixture()
            ->file('packages/event-bus/tests/EventBusTest.php', "<?php\n\nfinal class EventBusTest {}\n")
            ->file('packages/retry/tests/RetryTest.php', "<?php\n\nfinal class RetryTest {}\n")
            ->package('event-bus', 'rootEntry: true')
            ->package('retry', 'rootEntry: true')
            ->package('time')
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/event-bus/composer.json'] ?? '', 'event-bus');
        Assert::same($composer['autoload'] ?? null, ['psr-4' => ['Thesis\\' => 'src/']]);
        Assert::same($composer['autoload-dev'] ?? null, ['psr-4' => ['Thesis\\' => 'tests/']]);
        $root = Json::decode($files['composer.json'] ?? '', 'root');
        Assert::same($root['autoload'] ?? null, [
            'psr-4' => [
                'Thesis\\' => ['packages/event-bus/src/', 'packages/retry/src/'],
                'Thesis\Time\\' => 'packages/time/src/',
            ],
        ]);
        Assert::same($root['autoload-dev'] ?? null, [
            'psr-4' => ['Thesis\\' => ['packages/event-bus/tests/', 'packages/retry/tests/']],
        ]);
    }

    public static function requiresPlatformOfAPackageInItsComposerJsonAndTheRoot(): void
    {
        $fixture = new Fixture()
            ->package('bytes', "require: ['php-64bit' => '*']")
            ->package('time')
        ;

        $files = Generator::generate($fixture->load());

        $require = static fn(string $path) => Json::decode($files[$path] ?? '', $path)['require'] ?? null;
        Assert::same($require('packages/bytes/composer.json'), ['php' => '^8.5', 'php-64bit' => '*']);
        Assert::same($require('packages/time/composer.json'), ['php' => '^8.5']);
        Assert::same($require('composer.json'), ['php' => '^8.5', 'php-64bit' => '*']);
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

    public static function writesTableOfPackagesToReadme(): void
    {
        $fixture = new Fixture()->package('bytes');

        $files = Generator::generate($fixture->load());

        Assert::same($files['README.md'] ?? '', <<<'MD'
            # Fixture

            <!-- packages:start (generated by bin/monorepo sync, do not edit) -->

            | Package | Release | Description |
            |---|---|---|
            | [thesis/bytes](packages/bytes) | [![Release](https://img.shields.io/github/v/release/thesis-php/bytes)](https://github.com/thesis-php/bytes/releases) | Package bytes. |

            <!-- packages:end -->

            MD);
    }

    public static function writesHeadOfPackageReadmeInPlaceOfTitle(): void
    {
        $fixture = new Fixture()
            ->package('bytes')
            ->file('packages/bytes/README.md', "# Bytes\n\nUsage.\n")
        ;

        $files = Generator::generate($fixture->load());

        Assert::same($files['packages/bytes/README.md'] ?? '', <<<'MD'
            <!-- head:start (generated by bin/monorepo sync, do not edit) -->

            # Thesis Bytes

            [![PHP](https://img.shields.io/packagist/dependency-v/thesis/bytes/php)](https://packagist.org/packages/thesis/bytes)
            [![Release](https://img.shields.io/github/v/release/thesis-php/bytes)](https://github.com/thesis-php/bytes/releases)
            [![License](https://img.shields.io/packagist/l/thesis/bytes)](https://github.com/thesis-php/bytes/blob/main/LICENSE)
            [![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/thesis-php/thesis/blob/main/phpstan.neon)

            > This read-only repository mirrors
            > [`packages/bytes`](https://github.com/thesis-php/thesis/tree/main/packages/bytes).
            > Open issues and pull requests in the [thesis-php/thesis](https://github.com/thesis-php/thesis) monorepo.
            >
            > [Report a bug](https://github.com/thesis-php/thesis/issues/new?template=bug.yml) · [Request a feature](https://github.com/thesis-php/thesis/issues/new?template=feature.yml) · [Changelog](CHANGELOG.md)
            > · [Contributing](CONTRIBUTING.md)

            <!-- head:end -->

            Usage.

            MD);
    }

    public static function writesCoverageAndMutationBadgesOfPackageWithTests(): void
    {
        $fixture = self::fixture()->file('packages/bytes/README.md', "Usage.\n");

        $files = Generator::generate($fixture->load());

        Assert::string($files['packages/bytes/README.md'] ?? '')->contains(<<<'MD'
            [![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/thesis-php/thesis/blob/main/phpstan.neon)
            [![Coverage](https://codecov.io/gh/thesis-php/thesis/branch/main/graph/badge.svg?component=bytes)](https://app.codecov.io/gh/thesis-php/thesis/tree/main/packages/bytes/src)
            [![Mutation score](https://img.shields.io/endpoint?url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fthesis-php%2Fthesis%2Fmain%3Fmodule%3Dbytes)](https://dashboard.stryker-mutator.io/reports/github.com/thesis-php/thesis/main?module=bytes)

            > This read-only repository mirrors
            MD);
    }

    public static function writesNoMutationBadgeOfPackageWithoutMutationTesting(): void
    {
        $fixture = new Fixture()
            ->file('packages/bytes/tests/BytesTest.php', "<?php\n\nfinal class BytesTest {}\n")
            ->file('packages/bytes/README.md', "Usage.\n")
            ->package('bytes', 'mutationTesting: false')
        ;

        $files = Generator::generate($fixture->load());

        Assert::string($files['packages/bytes/README.md'] ?? '')->contains(<<<'MD'
            [![Coverage](https://codecov.io/gh/thesis-php/thesis/branch/main/graph/badge.svg?component=bytes)](https://app.codecov.io/gh/thesis-php/thesis/tree/main/packages/bytes/src)

            > This read-only repository mirrors
            MD);
    }

    public static function writesCodecovComponentOfEveryPackageWithTests(): void
    {
        $fixture = self::fixture()->package('stan');

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['.github/codecov.yml'] ?? '')
            ->endsWith(<<<'YAML'
                component_management:
                  individual_components:
                    - component_id: async
                      name: thesis/async
                      paths:
                        - packages/async/src/**
                      flag_regexes:
                        - '^async$'
                    - component_id: bytes
                      name: thesis/bytes
                      paths:
                        - packages/bytes/src/**
                      flag_regexes:
                        - '^bytes$'

                YAML)
        ;
    }

    public static function replacesHeadOfPackageReadme(): void
    {
        $start = Generator::HEAD_START;
        $end = Generator::HEAD_END;
        $fixture = new Fixture()
            ->package('bytes')
            ->file('packages/bytes/README.md', <<<MD
                {$start}
                # Old title
                {$end}

                # Section

                Usage.

                MD)
        ;

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['packages/bytes/README.md'] ?? '')
            ->startsWith(Generator::HEAD_START . "\n\n# Thesis Bytes\n\n[![PHP]")
            ->endsWith(Generator::HEAD_END . "\n\n# Section\n\nUsage.\n")
            ->notContains('Old')
        ;
    }

    public static function writesTitleOfManifestToPackageReadme(): void
    {
        $fixture = new Fixture()
            ->package('bytes', "title: 'Thesis BYTES'")
            ->file('packages/bytes/README.md', "Usage.\n")
        ;

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['packages/bytes/README.md'] ?? '')
            ->startsWith(Generator::HEAD_START . "\n\n# Thesis BYTES\n\n")
            ->endsWith(Generator::HEAD_END . "\n\nUsage.\n")
        ;
    }

    public static function writesContributingOfPackage(): void
    {
        $fixture = new Fixture()->package('bytes');

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['packages/bytes/CONTRIBUTING.md'] ?? '')
            ->contains("# Contributing to thesis/bytes\n")
            ->contains('https://github.com/thesis-php/thesis/tree/main/packages/bytes')
            ->contains('`make test P=bytes`')
            ->contains('`packages/bytes/CHANGELOG.md`')
            ->contains('https://github.com/thesis-php/thesis/issues/new?template=bug.yml')
            ->contains('Choose `thesis/bytes`')
        ;
    }

    public static function writesPackagesToDropdownsOfIssueForms(): void
    {
        $fixture = new Fixture()
            ->package('bytes')
            ->package('async')
        ;

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['.github/ISSUE_TEMPLATE/bug.yml'] ?? '')
            ->contains("      options:\n        - thesis/async\n        - thesis/bytes\n    validations:")
        ;
        Assert
            ::string($files['.github/ISSUE_TEMPLATE/feature.yml'] ?? '')
            ->contains("        - thesis/bytes\n        - A new package or the monorepo\n")
        ;
    }

    public static function writesSecurityPolicyOfPackage(): void
    {
        $fixture = new Fixture()->package('bytes');

        $files = Generator::generate($fixture->load());

        Assert
            ::string($files['packages/bytes/SECURITY.md'] ?? '')
            ->contains("# Security policy of thesis/bytes\n")
            ->contains('https://github.com/thesis-php/thesis/security/advisories/new')
        ;
    }

    public static function failsOnPhpstanConfigWithoutLevel(): void
    {
        $fixture = new Fixture()
            ->file('phpstan.neon', "parameters:\n    paths: [packages]\n")
            ->package('bytes')
            ->file('packages/bytes/README.md', "# Bytes\n")
        ;

        Expect::exception(MonorepoException::class)->withMessage('phpstan.neon must set parameters.level.');

        Generator::generate($fixture->load());
    }

    public static function writesKeywordsOfManifestAndConfig(): void
    {
        $fixture = new Fixture()
            ->file('monorepo.php', <<<'PHP'
                <?php

                return new Thesis\Monorepo\Config(
                    php: '^8.5',
                    description: 'Fixture.',
                    copyrightHolder: 'Fixture',
                    licenseSince: 2026,
                    keywords: ['php', 'monorepo'],
                );
                PHP)
            ->package('bytes', "keywords: ['binary', 'php-8']")
        ;

        $files = Generator::generate($fixture->load());

        $keywords = static fn(string $path) => Json::decode($files[$path] ?? '', $path)['keywords'] ?? null;
        Assert::same($keywords('packages/bytes/composer.json'), ['binary', 'php-8']);
        Assert::same($keywords('composer.json'), ['php', 'monorepo']);
    }

    #[DataSet([
        "'Binary'",
        'packages/bytes/package.php: the keyword "Binary" is not a topic of GitHub: lowercase letters, digits and hyphens, at most 50 characters.',
    ])]
    #[DataSet([
        "'-binary'",
        'packages/bytes/package.php: the keyword "-binary" is not a topic of GitHub: lowercase letters, digits and hyphens, at most 50 characters.',
    ])]
    #[DataSet(["'binary', 'binary'", 'packages/bytes/package.php: a keyword is repeated.'])]
    public static function failsOnKeywordThatIsNotTopic(string $keywords, string $error): void
    {
        $fixture = new Fixture()->package('bytes', "keywords: [{$keywords}]");

        Expect::exception(MonorepoException::class)->withMessage($error);

        Generator::generate($fixture->load());
    }

    public static function failsOnMoreKeywordsThanTopics(): void
    {
        $keywords = implode(', ', array_map(static fn(int $i) => "'k{$i}'", range(1, 21)));
        $fixture = new Fixture()->package('bytes', "keywords: [{$keywords}]");

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/bytes/package.php: more than 20 keywords, GitHub takes at most 20 topics.',
        );

        Generator::generate($fixture->load());
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

    public static function listsWhatSplitOfPackageHas(): void
    {
        $fixture = new Fixture()
            ->file('packages/bytes/bin/bytes', "#!/usr/bin/env php\n<?php\n")
            ->file('packages/bytes/polyfill/Bytes.php', "<?php\n")
            ->file('packages/bytes/skills/bytes/SKILL.md', "# Bytes\n")
            ->package('bytes', "dist: ['polyfill'], bin: ['bin/bytes']")
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/bytes/composer.json'] ?? '', 'bytes');
        Assert::same($composer['bin'] ?? null, ['bin/bytes']);
        Assert::same($files['packages/bytes/.split'] ?? '', <<<'TXT'
            # Generated by bin/monorepo sync, do not edit.
            # The split repository has only these entries of the package.
            CHANGELOG.md
            CONTRIBUTING.md
            LICENSE
            README.md
            SECURITY.md
            bin
            composer.json
            polyfill
            skills
            src

            TXT);
        Assert::false(isset($files['packages/bytes/.gitattributes']));
    }

    public static function failsOnDistPathThatDoesNotExist(): void
    {
        $fixture = new Fixture()->package('bytes', "dist: ['polyfill']");

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/bytes/package.php: the dist path polyfill does not exist, fix or remove it.',
        );

        Generator::generate($fixture->load());
    }

    /**
     * The split keeps whole entries of the directory of the package: a path inside one would name no entry,
     * and the split would silently lose it.
     */
    public static function failsOnDistPathThatIsNotTopLevel(): void
    {
        $fixture = new Fixture()
            ->file('packages/bytes/resources/schema.json', "{}\n")
            ->package('bytes', "dist: ['resources/schema.json']")
        ;

        Expect::exception(MonorepoException::class)->withMessage(
            'packages/bytes/package.php: the dist path resources/schema.json is not a top-level entry of the package, name resources instead.',
        );

        Generator::generate($fixture->load());
    }

    #[DataSet(['See [async](../async/README.md).', '../async/README.md'])]
    #[DataSet(['See [async](docs/../../async "Async").', 'docs/../../async'])]
    #[DataSet(['See [async](<../async/README.md>).', '../async/README.md'])]
    #[DataSet(['See [the root](/AGENTS.md#topics).', '/AGENTS.md#topics'])]
    #[DataSet(["See [async][1].\n\n[1]: ../async/README.md", '../async/README.md'])]
    #[DataSet(['<img src="../../logo.png" alt="Logo">', '../../logo.png'])]
    public static function failsOnReadmeLinkOutsideOfPackage(string $readme, string $link): void
    {
        $fixture = new Fixture()
            ->package('bytes')
            ->file('packages/bytes/README.md', "# Bytes\n\n{$readme}\n")
        ;

        Expect::exception(MonorepoException::class)->withMessage(\sprintf(
            'packages/bytes/README.md: %s links outside of the package, which the split repository and Packagist do not have: link a package by https://github.com/thesis-php/<name>.',
            $link,
        ));

        Generator::generate($fixture->load());
    }

    public static function allowsReadmeLinksWithinPackageAndUrls(): void
    {
        $fixture = new Fixture()
            ->package('bytes')
            ->file('packages/bytes/README.md', <<<'MD'
                # Bytes

                See [async](https://github.com/thesis-php/async), [usage](#usage), [skills](./skills/../src/Bytes.php),
                [mail](mailto:bytes@example.com) and [docs][docs].

                [docs]: docs/usage.md

                ```php
                $path = '[x](../async)';
                ```

                Not a link: `[x](../async)`.
                MD)
        ;

        $files = Generator::generate($fixture->load());

        Assert::true(isset($files['packages/bytes/composer.json']));
    }

    public static function autoloadsFilesWithoutClassLikeDeclarations(): void
    {
        $fixture = self
            ::fixture()
            ->file('packages/async/src/api.php', "<?php\n\nfunction delay(): void {}\n")
            ->file('packages/async/src/Internal/internal.php', "<?php\n\nconst STDIO = new \\stdClass();\n")
            ->file('packages/async/src/platform.php', "<?php\n\nif (PHP_OS_FAMILY === 'Windows') {}\n")
            ->file('packages/async/src/anonymous.php', "<?php\n\nfunction any(): object { return new class {}; }\n")
            ->file('packages/async/src/name.php', "<?php\n\nfunction name(): string { return Task::class; }\n")
            ->file('packages/async/src/Task.php', "<?php\n\nfinal class Task {}\n")
            ->file('packages/async/src/Awaitable.php', "<?php\n\ninterface Awaitable {}\n")
            ->file('packages/async/src/Awaits.php', "<?php\n\ntrait Awaits {}\n")
            ->file('packages/async/src/State.php', "<?php\n\nenum State {}\n")
            ->file('packages/async/src/README.md', "# Not PHP\n")
            ->file('packages/async/tests/helpers.php', "<?php\n\nfunction fixture(): void {}\n")
            ->file('packages/async/codegen/generate.php', "<?php\n\nfunction generate(): void {}\n")
            ->package('async', "files: ['src/Task.php'], devFiles: ['codegen/generate.php']")
        ;

        $files = Generator::generate($fixture->load());

        $composer = Json::decode($files['packages/async/composer.json'] ?? '', 'async');
        Assert::same($composer['autoload'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'src/'],
            'files' => [
                'src/Internal/internal.php',
                'src/anonymous.php',
                'src/api.php',
                'src/name.php',
                'src/platform.php',
                'src/Task.php',
            ],
        ]);
        Assert::same($composer['autoload-dev'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'tests/'],
            'files' => ['tests/helpers.php', 'codegen/generate.php'],
        ]);
        $root = Json::decode($files['composer.json'] ?? '', 'root');
        Assert::same($root['autoload-dev'] ?? null, [
            'psr-4' => ['Thesis\Async\\' => 'packages/async/tests/', 'Thesis\Bytes\\' => 'packages/bytes/tests/'],
            'files' => ['packages/async/tests/helpers.php', 'packages/async/codegen/generate.php'],
        ]);
    }

    #[DataSet(['files', 'src/api.php'])]
    #[DataSet(['devFiles', 'tests/helpers.php'])]
    public static function failsOnAutoloadFileAlreadyDiscovered(string $argument, string $path): void
    {
        $fixture = new Fixture()
            ->package('async', "{$argument}: ['{$path}']")
            ->file("packages/async/{$path}", "<?php\n\nfunction helper(): void {}\n")
        ;

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

    public static function writesNoAutoloadDevOfPackageWithoutTests(): void
    {
        $fixture = self::fixture()->package('stan');

        $files = Generator::generate($fixture->load());

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
                require: ['psr/log' => '^3.0', 'thesis/bytes' => '*'],
                requireDev: ['amphp/amp' => '^3.0', 'psr/clock' => '^1.1'],
                PHP)
        ;
    }
}
