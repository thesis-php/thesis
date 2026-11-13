<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * Tracks the current scope of every fiber.
 *
 * @internal
 */
final class Context
{
    /** @var ?\WeakMap<object, ScopeImpl> current scope of every fiber (keyed by \Fiber) that has entered one */
    private static ?\WeakMap $fibers = null;

    /** current scope of {main} */
    private static ?ScopeImpl $main = null;

    private static ?ScopeImpl $root = null;

    public static function current(): ScopeImpl
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
    public static function enter(ScopeImpl $scope, callable $body): mixed
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

    private static function root(): ScopeImpl
    {
        return self::$root ??= new ScopeImpl();
    }
}
