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

    public function shortensFileUnderProjectRoot(): void
    {
        $file = 'packages/source-location/tests/LocationTest.php';

        Assert::same(new Location(__FILE__, 1)->shortFile, $file);
    }

    public function keepsFileOutsideProjectRoot(): void
    {
        Assert::same(new Location('/elsewhere/foo.php', 1)->shortFile, '/elsewhere/foo.php');
    }
}
