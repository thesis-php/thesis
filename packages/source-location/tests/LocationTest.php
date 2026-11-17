<?php

declare(strict_types=1);

namespace Thesis\SourceLocation;

use Testo\Assert;
use Testo\Test;

#[Test]
final class LocationTest
{
    public function keepsFileAndLine(): void
    {
        $location = Location::of('/app/src/foo.php', 12);

        Assert::same($location->file, '/app/src/foo.php');
        Assert::same($location->line, 12);
        Assert::same((string) $location, '/app/src/foo.php:12');
    }

    public function resolvesCodeOfEval(): void
    {
        Assert::equals(Location::of("/app/src/foo.php(7) : eval()'d code", 3), new Location('/app/src/foo.php', 7));
    }

    public function resolvesNestedEvalToOutermost(): void
    {
        Assert::equals(
            actual: Location::of("/app/src/foo.php(7) : eval()'d code(2) : eval()'d code", 1),
            expected: new Location('/app/src/foo.php', 7),
        );
    }

    public function keepsEvalOfLineZero(): void
    {
        $file = "/app/src/foo.php(0) : eval()'d code";

        Assert::equals(Location::of($file, 3), new Location($file, 3));
    }

    public function resolvesEvalOfFileWithNewline(): void
    {
        Assert::equals(Location::of("/app/a\nb/foo.php(7) : eval()'d code", 3), new Location("/app/a\nb/foo.php", 7));
    }

    public function locatesFunction(): void
    {
        $closure = static fn() => null;

        Assert::equals(Location::ofFunction(new \ReflectionFunction($closure)), new Location(__FILE__, __LINE__ - 2));
    }

    public function locatesFunctionDeclaredInEval(): void
    {
        /** @var \Closure $closure */
        $closure = eval('return eval("return static fn() => null;");');

        Assert::equals(Location::ofFunction(new \ReflectionFunction($closure)), new Location(__FILE__, __LINE__ - 2));
    }

    public function doesNotLocateInternalFunction(): void
    {
        Assert::null(Location::ofFunction(new \ReflectionFunction('strlen')));
    }

    public function doesNotLocateFunctionWithoutFile(): void
    {
        $function = new class (static fn() => null) extends \ReflectionFunction {
            public function getFileName(): string
            {
                return '';
            }
        };

        Assert::null(Location::ofFunction($function));
    }

    public function shortensFileUnderProjectRoot(): void
    {
        $file = 'packages/source-location/tests/LocationTest.php';

        Assert::same(new Location(__FILE__, 1)->shortFile, $file);
    }

    public function keepsFileIfProjectRootIsOutsideOpenBasedir(): void
    {
        $code = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            set_error_handler(static function (int $type, string $message) {
                if ((error_reporting() & $type) !== 0) {
                    throw new \ErrorException($message);
                }

                return false;
            });
            ini_set('open_basedir', $argv[1] . '/packages:' . $argv[1] . '/vendor');
            echo new \Thesis\SourceLocation\Location('/elsewhere/foo.php', 1)->shortFile;
            PHP;
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code, \dirname(__DIR__, 3)],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1], $pipes[2]));

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        Assert::same(proc_close($process), 0, $output);
        Assert::same($output, '/elsewhere/foo.php');
    }

    public function keepsFileOutsideProjectRoot(): void
    {
        Assert::same(new Location('/elsewhere/foo.php', 1)->shortFile, '/elsewhere/foo.php');
    }
}
