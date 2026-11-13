<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Scope;

/**
 * Tracks the current scope of every fiber.
 *
 * @internal
 */
final class Context
{
    /** @var ?\WeakMap<object, Scope> current scope of every fiber (keyed by \Fiber) that has entered one */
    private static ?\WeakMap $fibers = null;

    /** current scope of {main} */
    private static ?Scope $main = null;

    private static ?Scope $root = null;

    public static function current(): Scope
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return self::$main ?? self::root();
        }

        return self::$fibers[$fiber] ?? self::root();
    }

    /**
     * Runs $body in the current fiber with $scope as the current one.
     *
     * @template T
     * @param callable(): T $body
     * @return T
     */
    public static function enter(Scope $scope, callable $body): mixed
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $previous = self::$main;
            self::$main = $scope;

            try {
                return $body();
            } finally {
                self::$main = $previous;
            }
        }

        $fibers = self::$fibers ??= new \WeakMap();
        $previous = $fibers[$fiber] ?? null;
        $fibers[$fiber] = $scope;

        try {
            return $body();
        } finally {
            if ($previous === null) {
                unset($fibers[$fiber]);
            } else {
                $fibers[$fiber] = $previous;
            }
        }
    }

    private static function root(): Scope
    {
        return self::$root ??= new Scope();
    }
}
