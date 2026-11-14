<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Generator;
use Thesis\Monorepo\Internal\Json;

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
            'require-dev' => ['amphp/amp' => '^3.0'],
            'autoload' => ['psr-4' => ['Thesis\Async\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Thesis\Async\\' => 'tests/']],
            'config' => ['sort-packages' => true],
        ]);
    }

    public static function mergesPackagesIntoRootComposerJson(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert::same(Json::decode($files['composer.json'] ?? '', 'root'), [
            'name' => 'thesis/monorepo',
            'require' => ['php' => '^8.5', 'ext-bcmath' => '*', 'revolt/event-loop' => '^1.0'],
            'require-dev' => ['amphp/amp' => '^3.0'],
            'replace' => ['thesis/async' => 'self.version', 'thesis/bytes' => 'self.version'],
            'autoload' => [
                'psr-4' => ['Thesis\Async\\' => 'packages/async/src/', 'Thesis\Bytes\\' => 'packages/bytes/src/'],
            ],
            'autoload-dev' => [
                'psr-4' => ['Thesis\Async\\' => 'packages/async/tests/', 'Thesis\Bytes\\' => 'packages/bytes/tests/'],
            ],
        ]);
    }

    public static function writesCatalogBetweenMarkers(): void
    {
        $fixture = self::fixture();

        $files = Generator::generate($fixture->load());

        Assert::string($files['AGENTS.md'] ?? '')
            ->contains("- `bytes`: Package bytes.\n")
            ->contains("- `async`: Package async. Uses: bytes.\n")
        ;
    }

    public static function writesTestoSuitesWithPlugins(): void
    {
        $fixture = self::fixture()->file('packages/async/testo.plugins.php', "<?php\n\nreturn [];\n");

        $files = Generator::generate($fixture->load());

        Assert::string($files['testo.php'] ?? '')
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
            ->package('bytes', "layer: Layer::Primitive, require: ['ext-bcmath' => '*']")
            ->package('async', <<<'PHP'
                layer: Layer::Runtime,
                uses: ['bytes'],
                require: ['revolt/event-loop' => '^1.0'],
                requireDev: ['amphp/amp' => '^3.0'],
                PHP)
        ;
    }
}
