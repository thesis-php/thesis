<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;

#[Test]
final class FiberLocalTest
{
    public function valueOfMainIsKept(): void
    {
        /** @var FiberLocal<\stdClass> */
        $local = new FiberLocal();
        $value = new \stdClass();

        Assert::null($local->get());

        $local->set($value);

        Assert::same($local->get(), $value);

        $local->unset();

        Assert::null($local->get());
    }

    public function everyFiberHasItsOwnValue(): void
    {
        /** @var FiberLocal<\stdClass> */
        $local = new FiberLocal();
        $main = new \stdClass();
        $local->set($main);
        $values = [];
        $fibers = [];

        foreach (['a', 'b'] as $name) {
            $fiber = new \Fiber(static function () use ($local, &$values, $name) {
                $values[$name . ' before'] = $local->get();
                $value = new \stdClass();
                $local->set($value);
                \Fiber::suspend();
                $values[$name . ' after'] = $local->get() === $value;
                $local->unset();
                $values[$name . ' unset'] = $local->get();
            });
            $fiber->start();
            $fibers[] = $fiber;
        }

        foreach ($fibers as $fiber) {
            $fiber->resume();
        }

        Assert::same($values, [
            'a before' => null,
            'b before' => null,
            'a after' => true,
            'a unset' => null,
            'b after' => true,
            'b unset' => null,
        ]);
        Assert::same($local->get(), $main);
    }

    public function valueGoesAwayWithFiber(): void
    {
        /** @var FiberLocal<\stdClass> */
        $local = new FiberLocal();
        $value = new \stdClass();
        $reference = \WeakReference::create($value);
        $fiber = new \Fiber(static function () use ($local, $value) {
            $local->set($value);
        });
        $fiber->start();
        unset($value);

        Assert::notNull($reference->get());

        unset($fiber);

        Assert::null($reference->get());
    }
}
