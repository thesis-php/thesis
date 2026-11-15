<?php

declare(strict_types=1);

namespace Thesis\PhpCsFixer\Tests;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The fixtures go through the php-cs-fixer binary with the config of the monorepo: the classes of PHP CS Fixer
 * are not in vendor/, and the fixer is only correct together with the rules that run around it.
 */
#[Test]
final readonly class StaticCallChainFixerTest
{
    private const string SEPARATOR = "-----\n";

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(__DIR__ . '/Fixture/*.php.inc');
        \assert($files !== false);

        foreach ($files as $file) {
            $name = basename($file, '.php.inc');

            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public static function fixes(string $fixture): void
    {
        [, $expected] = self::read($fixture);

        Assert::same(self::fixed()[$fixture] ?? null, $expected);
    }

    #[DataProvider('fixtures')]
    public static function leavesFixedCodeAsIs(string $fixture): void
    {
        [, $expected] = self::read($fixture);

        Assert::same(self::fixed()["{$fixture}.expected"] ?? null, $expected);
    }

    /**
     * @return array<string, string> the input (fixture) and the expected output (fixture.expected) after php-cs-fixer,
     *     all fixtures in one run
     */
    private static function fixed(): array
    {
        /** @var ?array<string, string> $fixed */
        static $fixed = null;

        if ($fixed !== null) {
            return $fixed;
        }

        $dir = sys_get_temp_dir() . '/php-cs-fixer-' . bin2hex(random_bytes(8));
        mkdir($dir);

        try {
            foreach (self::fixtures() as $name => [$fixture]) {
                [$input, $expected] = self::read($fixture);
                file_put_contents("{$dir}/{$name}.php", $input);
                file_put_contents("{$dir}/{$name}.expected.php", $expected);
            }

            $process = proc_open(
                command: [
                    'php-cs-fixer',
                    'fix',
                    '--config=' . \dirname(__DIR__, 2) . '/php-cs-fixer.php',
                    '--path-mode=override',
                    '--using-cache=no',
                    // The tests run on every PHP version of CI, the fixer refuses the ones it does not support yet.
                    // The fixtures have no syntax of those versions, and the code style is checked on a supported one.
                    '--allow-unsupported-php-version=yes',
                    '--sequential',
                    '--quiet',
                    $dir,
                ],
                descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                pipes: $pipes,
            );
            \assert($process !== false && isset($pipes[1], $pipes[2]));
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            Assert::same(proc_close($process), 0, $output);

            $fixed = [];

            $files = glob($dir . '/*.php');
            \assert($files !== false);

            foreach ($files as $file) {
                $fixed[basename($file, '.php')] = (string) file_get_contents($file);
            }

            return $fixed;
        } finally {
            /** @var \SplFileInfo $file */
            foreach (new \FilesystemIterator($dir) as $file) {
                unlink($file->getPathname());
            }

            rmdir($dir);
        }
    }

    /**
     * @return array{string, string} the input and the expected output, the same when the fixture has no separator
     */
    private static function read(string $fixture): array
    {
        $content = (string) file_get_contents(__DIR__ . "/Fixture/{$fixture}.php.inc");
        $parts = explode(self::SEPARATOR, $content, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
