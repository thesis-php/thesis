<?php

declare(strict_types=1);

namespace Thesis\SourceLocation\Library;

use Thesis\SourceLocation\Caller;
use function Thesis\SourceLocation\caller;

/**
 * Stands for a library: caller() skips this directory.
 */
final class Library
{
    public static function call(): ?Caller
    {
        return self::inner();
    }

    /**
     * @param positive-int $limit
     */
    public static function callWithLimit(int $limit): ?Caller
    {
        return caller([__DIR__], $limit);
    }

    public static function callSkippingEverything(): ?Caller
    {
        return caller([__DIR__, \dirname(__DIR__, 4)]);
    }

    /**
     * @param callable(): ?Caller $fn
     */
    public static function callBack(callable $fn): ?Caller
    {
        return $fn();
    }

    private static function inner(): ?Caller
    {
        return caller([__DIR__ . \DIRECTORY_SEPARATOR]);
    }
}
