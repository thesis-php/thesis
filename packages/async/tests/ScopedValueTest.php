<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\sec;

#[Test]
final class ScopedValueTest
{
    public function valueIsBoundWhileFnRuns(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        Assert::null($value->get());
        Assert::same($value->with('bound', $value->get(...)), 'bound');
        Assert::null($value->get());
    }

    public function defaultIsReturnedWhereNothingIsBound(): void
    {
        $value = new ScopedValue('default');

        $observed = [
            $value->get(),
            $value->with('bound', $value->get(...)),
            $value->get(),
            group(static fn(Group $group) => $group->spawn($value->get(...))->await()),
        ];

        Assert::same($observed, ['default', 'bound', 'default', 'default']);
    }

    public function boundNullIsNotReplacedByDefault(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue('default');

        $inTask = static fn() => group(static fn(Group $group) => $group->spawn($value->get(...))->await());

        $observed = $value->with('outer', static fn() => [
            $value->with(null, $value->get(...)),
            $value->with(null, $inTask),
        ]);

        Assert::same($observed, [null, null]);
    }

    public function isBoundTellsBindingFromAbsenceWhateverTheValue(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue('default');
        /** @var ScopedValue<?string> */
        $other = new ScopedValue();

        $observed = [
            $value->isBound(),
            $value->with('bound', $value->isBound(...)),
            $value->with(null, $value->isBound(...)),
            $value->with('default', $value->isBound(...)),
            $other->with('other', $value->isBound(...)),
            $value->with(
                value: 'bound',
                fn: static fn() => group(static fn(Group $group) => $group->spawn($value->isBound(...))->await()),
            ),
            $value->isBound(),
        ];

        Assert::same($observed, [false, true, true, true, false, true, false]);
    }

    public function nestedWithRestoresOuterValue(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = $value->with('outer', static fn() => [
            $value->get(),
            $value->with('inner', $value->get(...)),
            $value->with(null, $value->get(...)),
            $value->get(),
        ]);

        Assert::same($observed, ['outer', 'inner', null, 'outer']);
    }

    public function previousValueIsRestoredWhenFnFails(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();
        $failure = new \RuntimeException('failed');

        $observed = $value->with('outer', static fn() => [
            thrown(static fn() => $value->with('inner', static fn() => throw $failure)),
            $value->get(),
        ]);

        Assert::same($observed, [$failure, 'outer']);
        Assert::null($value->get());
    }

    public function nestedWithRestoresOuterValueInTask(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = [];

        group(static function (Group $group) use ($value, &$observed) {
            $group->spawn(static function () use ($value, &$observed) {
                $value->with('outer', static function () use ($value, &$observed) {
                    $observed[] = $value->with('inner', $value->get(...));
                    $observed[] = $value->get();
                });
            });
        });

        Assert::same($observed, ['inner', 'outer']);
    }

    public function valuesAreIndependent(): void
    {
        /** @var ScopedValue<?string> */
        $a = new ScopedValue();
        /** @var ScopedValue<?string> */
        $b = new ScopedValue();

        $observed = [];

        $a->with('a', static function () use ($a, $b, &$observed) {
            $b->with('b', static function () use ($a, $b, &$observed) {
                $observed[] = [$a->get(), $b->get()];

                $a->with('a2', static function () use ($a, $b, &$observed) {
                    $observed[] = [$a->get(), $b->get()];
                });
            });
        });

        Assert::same($observed, [['a', 'b'], ['a2', 'b']]);
    }

    public function taskInheritsValueBoundAroundGroup(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = $value->with('request', static fn() => group(static function (Group $group) use ($value) {
            $handle = $group->spawn(static function () use ($value): ?string {
                delay(sec(1));

                return $value->get();
            });
            $daemon = $group->daemon($value->get(...));

            return [$handle->await(), $daemon->await()];
        }));

        Assert::same($observed, ['request', 'request']);
    }

    public function taskInheritsValueBoundAroundSpawn(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = group(static function (Group $group) use ($value) {
            $inside = $value->with('spawn', static fn() => $group->spawn($value->get(...)));
            $outside = $group->spawn($value->get(...));

            return [$inside->await(), $outside->await()];
        });

        Assert::same($observed, ['spawn', null]);
    }

    public function taskKeepsValuesCapturedAtSpawn(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = $value->with('spawn', static fn() => group(static function (Group $group) use ($value) {
            $handle = $group->spawn($value->get(...));

            // Rebound before the task starts: the task still sees what was bound at spawn().
            return $value->with('later', $handle->await(...));
        }));

        Assert::same($observed, 'spawn');
    }

    public function bindingInTaskDoesNotLeakIntoParentOrSiblings(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = $value->with('parent', static fn() => group(static function (Group $group) use ($value) {
            $first = $group->spawn(static fn(): ?string => $value->with(
                value: 'first',
                fn: static function () use ($value): ?string {
                    delay(sec(2));

                    return $value->get();
                },
            ));
            $second = $group->spawn(static function () use ($value): ?string {
                delay(sec(1));

                return $value->get();
            });
            delay(sec(1));
            $inBody = $value->get();

            return [$first->await(), $second->await(), $inBody, $value->get()];
        }));

        Assert::same($observed, ['first', 'parent', 'parent', 'parent']);
    }

    public function bindingInTaskDoesNotLeakIntoNextTaskInSameFiber(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();
        $first = null;
        $second = null;
        $seen = 'not run';

        group(static function (Group $group) use ($value, &$first, &$second, &$seen) {
            // Neither task waits, so the second runs in the fiber of the pool the first has left.
            $group->spawn(static function () use ($value, &$first) {
                $first = \Fiber::getCurrent();
                $value->with('first', static fn() => null);
            });
            $group->spawn(static function () use ($value, &$second, &$seen) {
                $second = \Fiber::getCurrent();
                $seen = $value->get();
            });
        });

        Assert::same($first, $second);
        Assert::null($seen);
    }

    public function nestedTaskInheritsFromTask(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = group(static fn(Group $outer) => $outer->spawn(
            task: static fn() => $value->with('task', static fn(): ?string => group(
                body: static fn(Group $inner): ?string => $inner->spawn(static fn(): ?string => $value->get())->await(),
            )),
        )->await());

        Assert::same($observed, 'task');
    }

    public function shieldAndTimeoutDoNotHideValues(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = $value->with('bound', static fn() => timeout(sec(1000), static fn() => shield($value->get(...))));

        Assert::same($observed, 'bound');
    }

    public function valueDoesNotLeakIntoLaterTask(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();

        $observed = group(static function (Group $group) use ($value) {
            $bound = $value->with('bound', static fn() => $group->spawn($value->get(...)));
            $unbound = $group->spawn($value->get(...));

            return [$bound->await(), $unbound->await()];
        });

        Assert::same($observed, ['bound', null]);
    }

    public function everyFiberHasItsOwnValue(): void
    {
        /** @var ScopedValue<?string> */
        $value = new ScopedValue();
        $observed = [];

        $value->with('main', static function () use ($value, &$observed) {
            $fibers = [];

            foreach (['a', 'b'] as $name) {
                // A fiber created by hand is not a task: it inherits nothing.
                $fiber = new \Fiber(static function () use ($value, &$observed, $name) {
                    $observed[$name . ' before'] = $value->get();
                    $value->with($name, static function () use ($value, &$observed, $name) {
                        \Fiber::suspend();
                        $observed[$name . ' after'] = $value->get();
                    });
                    $observed[$name . ' unbound'] = $value->get();
                });
                $fiber->start();
                $fibers[] = $fiber;
            }

            foreach ($fibers as $fiber) {
                $fiber->resume();
            }

            $observed['main'] = $value->get();
        });

        Assert::same($observed, [
            'a before' => null,
            'b before' => null,
            'a after' => 'a',
            'a unbound' => null,
            'b after' => 'b',
            'b unbound' => null,
            'main' => 'main',
        ]);
    }

    public function valueGoesAwayWithFiber(): void
    {
        /** @var ScopedValue<?\stdClass> */
        $value = new ScopedValue();
        $object = new \stdClass();
        $reference = \WeakReference::create($object);
        $fiber = new \Fiber(static function () use ($value, $object) {
            $value->with($object, static fn() => \Fiber::suspend());
        });
        $fiber->start();
        unset($object);

        Assert::notNull($reference->get());

        unset($fiber);

        Assert::null($reference->get());
    }
}
