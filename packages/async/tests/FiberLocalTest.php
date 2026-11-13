<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class FiberLocalTest
{
    public function valueIsBoundWhileFnRuns(): void
    {
        /** @var FiberLocal<\stdClass> */
        $local = new FiberLocal();
        $value = new \stdClass();

        Assert::null($local->get());

        $result = $local->with($value, static fn() => $local->get());

        Assert::same($result, $value);
        Assert::null($local->get());
    }

    public function nestedWithRestoresOuterValue(): void
    {
        /** @var FiberLocal<string> */
        $local = new FiberLocal();

        $values = $local->with('outer', static fn() => [
            $local->get(),
            $local->with('inner', static fn() => $local->get()),
            $local->get(),
        ]);

        Assert::same($values, ['outer', 'inner', 'outer']);
        Assert::null($local->get());
    }

    public function previousValueIsRestoredWhenFnFails(): void
    {
        /** @var FiberLocal<string> */
        $local = new FiberLocal();
        $failure = new \RuntimeException('failed');

        $values = $local->with('outer', static fn() => [
            thrown(static fn() => $local->with('inner', static fn() => throw $failure)),
            $local->get(),
        ]);

        Assert::same($values, [$failure, 'outer']);
        Assert::null($local->get());
    }

    public function everyFiberHasItsOwnValue(): void
    {
        /** @var FiberLocal<string> */
        $local = new FiberLocal();
        $values = [];

        $local->with('main', static function () use ($local, &$values) {
            $fibers = [];

            foreach (['a', 'b'] as $name) {
                $fiber = new \Fiber(static function () use ($local, &$values, $name) {
                    $values[$name . ' before'] = $local->get();
                    $local->with($name, static function () use ($local, &$values, $name) {
                        \Fiber::suspend();
                        $values[$name . ' after'] = $local->get();
                    });
                    $values[$name . ' unbound'] = $local->get();
                });
                $fiber->start();
                $fibers[] = $fiber;
            }

            foreach ($fibers as $fiber) {
                $fiber->resume();
            }

            $values['main'] = $local->get();
        });

        Assert::same($values, [
            'a before' => null,
            'b before' => null,
            'a after' => 'a',
            'a unbound' => null,
            'b after' => 'b',
            'b unbound' => null,
            'main' => 'main',
        ]);
    }

    public function valueDoesNotLeakToNextTaskInSameFiber(): void
    {
        /** @var FiberLocal<string> */
        $local = new FiberLocal();
        $first = null;
        $second = null;
        $seen = 'not run';

        group(static function (Group $group) use ($local, &$first, &$second, &$seen) {
            // Neither task suspends, so Revolt runs both in the same fiber.
            $group->spawn(static function () use ($local, &$first) {
                $first = \Fiber::getCurrent();
                $local->with('first', static fn() => null);
            });
            $group->spawn(static function () use ($local, &$second, &$seen) {
                $second = \Fiber::getCurrent();
                $seen = $local->get();
            });
        });

        Assert::same($first, $second);
        Assert::null($seen);
    }

    public function taskDoesNotInheritValue(): void
    {
        /** @var FiberLocal<string> */
        $local = new FiberLocal();
        $seen = 'not run';

        $local->with('parent', static function () use ($local, &$seen) {
            group(static function (Group $group) use ($local, &$seen) {
                $group->spawn(static function () use ($local, &$seen) {
                    $seen = $local->get();
                });
            });
        });

        Assert::null($seen);
    }

    public function valueGoesAwayWithFiber(): void
    {
        /** @var FiberLocal<\stdClass> */
        $local = new FiberLocal();
        $value = new \stdClass();
        $reference = \WeakReference::create($value);
        $fiber = new \Fiber(static function () use ($local, $value) {
            $local->with($value, static fn() => \Fiber::suspend());
        });
        $fiber->start();
        unset($value);

        Assert::notNull($reference->get());

        unset($fiber);

        Assert::null($reference->get());
    }
}
