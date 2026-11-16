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

    /**
     * @return \Closure(): ?Caller
     */
    public static function closure(): \Closure
    {
        return static fn() => caller([__DIR__]);
    }

    /**
     * @return \Closure(): ?Caller a method of an anonymous class
     */
    public static function anonymousMethod(): \Closure
    {
        return new class {
            public function call(): ?Caller
            {
                return caller([__DIR__]);
            }
        }->call(...);
    }

    /**
     * @return \Closure(): ?Caller a closure declared in a method of an anonymous class
     */
    public static function closureOfAnonymousClass(): \Closure
    {
        return new class {
            /**
             * @return \Closure(): ?Caller
             */
            public function make(): \Closure
            {
                return static fn() => caller([__DIR__]);
            }
        }->make();
    }

    private static function inner(): ?Caller
    {
        return caller([__DIR__ . \DIRECTORY_SEPARATOR]);
    }
}
