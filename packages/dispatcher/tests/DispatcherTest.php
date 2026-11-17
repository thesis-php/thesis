<?php

declare(strict_types=1);

namespace Thesis;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Dispatcher\Fixtures\Base;
use Thesis\Dispatcher\Fixtures\Child;
use Thesis\Dispatcher\Fixtures\Event;
use Thesis\Dispatcher\Fixtures\Named;
use Thesis\Dispatcher\Fixtures\Other;
use Thesis\Dispatcher\Fixtures\Unrelated;
use function Thesis\Formatter\formatFunction;
use function Thesis\Formatter\formatParameter;

#[Test]
final class DispatcherTest
{
    /**
     * @return iterable<string, array{callable(never): void, list<class-string>}>
     */
    public static function listenersByType(): iterable
    {
        yield 'a class' => [static function (Base $event) {}, [Base::class, Child::class]];
        yield 'a final class' => [static function (Child $event) {}, [Child::class]];
        yield 'an interface' => [static function (Event $event) {}, [Base::class, Child::class, Other::class]];
        yield 'a union' => [static function (Other|Unrelated $event) {}, [Other::class, Unrelated::class]];
        yield 'an intersection' => [static function (Event&Named $event) {}, [Child::class]];
        yield 'a DNF' => [static function ((Event&Named)|Unrelated $event) {}, [Child::class, Unrelated::class]];
        yield 'object' => [static function (object $event) {}, self::ALL];
        yield 'mixed' => [static function (mixed $event) {}, self::ALL];
        yield 'no type' => [static function ($event) {}, self::ALL];
        yield 'self' => [new Child()->onSelf(...), [Base::class, Child::class]];
        yield 'parent' => [new Child()->onParent(...), [Base::class, Child::class]];
        yield 'self in a closure' => [Base::selfListener(), [Base::class, Child::class]];
        yield 'parent in a closure' => [Child::parentListener(), [Base::class, Child::class]];
    }
    private const array ALL = [Base::class, Child::class, Other::class, Unrelated::class];

    /**
     * @param callable(never): void $listener
     * @param list<class-string> $classes
     */
    #[DataProvider('listenersByType')]
    public function getsTheEventsOfTheTypeOfItsParameter(callable $listener, array $classes): void
    {
        $dispatcher = new Dispatcher([$listener]);

        Assert::same(array_values(array_filter(self::ALL, $dispatcher->listens(...))), $classes);
    }

    public function callsTheListenersInTheOrderTheyAreGiven(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher([
            static function (Event $event) use (&$calls) {
                $calls[] = 'event';
            },
            static function (Child $event) use (&$calls) {
                $calls[] = 'child';
            },
            static function (object $event) use (&$calls) {
                $calls[] = 'object';
            },
            static function (Unrelated $event) use (&$calls) {
                $calls[] = 'unrelated';
            },
        ]);

        $dispatcher->dispatch(new Child());
        $dispatcher->dispatch(new Other());

        Assert::same($calls, ['event', 'child', 'object', 'event', 'object']);
    }

    public function takesTheListenersFromAnIterable(): void
    {
        $calls = [];
        $listeners = (static function () use (&$calls) {
            yield 'b' => static function (Other $event) use (&$calls) {
                $calls[] = 'b';
            };
            yield 'a' => static function (Other $event) use (&$calls) {
                $calls[] = 'a';
            };
        })();
        $dispatcher = new Dispatcher($listeners);

        $dispatcher->dispatch(new Other());

        Assert::same($calls, ['b', 'a']);
    }

    public function ignoresTheKeysOfTheIterable(): void
    {
        $calls = [];
        $listeners = (static function () use (&$calls) {
            yield 'same' => static function (Other $event) use (&$calls) {
                $calls[] = 'first';
            };
            yield 'same' => static function (Other $event) use (&$calls) {
                $calls[] = 'second';
            };
        })();
        $dispatcher = new Dispatcher($listeners);

        $dispatcher->dispatch(new Other());

        Assert::same($calls, ['first', 'second']);
    }

    public function callsAListenerOnceForAnEventOfSeveralTypesOfItsUnion(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher([static function (Named|Event|Base $event) use (&$calls) {
            $calls[] = $event;
        }]);
        $event = new Child();

        $dispatcher->dispatch($event);
        $dispatcher->dispatch($event);

        Assert::same($calls, [$event, $event]);
    }

    public function passesTheEvent(): void
    {
        $listener = new Child();
        $dispatcher = new Dispatcher([$listener->onSelf(...), $listener->onSelf(...)]);

        $dispatcher->dispatch(new Child());
        $dispatcher->dispatch(new Unrelated());

        Assert::same($listener->calls, [Child::class, Child::class]);
    }

    public function takesAnInvokableObject(): void
    {
        $listener = new class {
            /**
             * @var list<object>
             */
            public array $calls = [];

            public function __invoke(Other $event): void
            {
                $this->calls[] = $event;
            }
        };
        $dispatcher = new Dispatcher([$listener]);
        $event = new Other();

        $dispatcher->dispatch($event);

        Assert::same($listener->calls, [$event]);
    }

    public function takesAListenerWithOptionalParametersAfterTheEvent(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher([static function (Other $event, int $times = 2) use (&$calls) {
            $calls[] = $times;
        }]);

        $dispatcher->dispatch(new Other());

        Assert::same($calls, [2]);
    }

    public function dispatchesNothingWithoutListeners(): void
    {
        $dispatcher = new Dispatcher();

        $dispatcher->dispatch(new Other());

        Assert::false($dispatcher->listens(Other::class));
    }

    public function stopsAtTheListenerThatThrows(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher([
            static function (Other $event) {
                throw new \RuntimeException('Failed.');
            },
            static function (Other $event) use (&$calls) {
                $calls[] = $event;
            },
        ]);

        $thrown = null;

        try {
            $dispatcher->dispatch(new Other());
        } catch (\RuntimeException $exception) {
            $thrown = $exception->getMessage();
        }

        Assert::same($thrown, 'Failed.');
        Assert::same($calls, []);
    }

    public function dispatchesFromAListener(): void
    {
        $calls = [];
        $dispatcher = null;
        $dispatcher = new Dispatcher([
            static function (Other $event) use (&$dispatcher, &$calls) {
                $calls[] = Other::class;
                $dispatcher?->dispatch(new Unrelated());
            },
            static function (Unrelated $event) use (&$calls) {
                $calls[] = Unrelated::class;
            },
        ]);

        $dispatcher->dispatch(new Other());

        Assert::same($calls, [Other::class, Unrelated::class]);
    }

    public function letsAListenerSuspendTheFiber(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher([
            static function (Other $event) use (&$calls) {
                $calls[] = \Fiber::suspend('suspended');
            },
            static function (Other $event) use (&$calls) {
                $calls[] = 'after';
            },
        ]);
        $fiber = new \Fiber($dispatcher->dispatch(...));

        Assert::same($fiber->start(new Other()), 'suspended');
        Assert::same($calls, []);

        $fiber->resume('resumed');

        Assert::true($fiber->isTerminated());
        Assert::same($calls, ['resumed', 'after']);
    }

    public function resolvesAClassLoadedAfterTheListenerIsGiven(): void
    {
        $dispatcher = new Dispatcher([static function (Event $event) {}]);
        $event = new class implements Event {};

        Assert::true($dispatcher->listens($event::class));
    }

    public function resolvesTheListenersOfAClassOnce(): void
    {
        $dispatcher = new Dispatcher([static function (Event $event) {}]);
        /** @var class-string $class a class that is not declared: is_a() asks the autoloaders for it every time */
        // @phpstan-ignore varTag.nativeType
        $class = 'Thesis\Dispatcher\Fixtures\NotDeclared';
        $loads = 0;
        $autoload = static function (string $loaded) use ($class, &$loads) {
            if ($loaded === $class) {
                ++$loads;
            }
        };
        spl_autoload_register($autoload);

        try {
            $dispatcher->listens($class);
            $dispatcher->listens($class);
        } finally {
            spl_autoload_unregister($autoload);
        }

        Assert::same($loads, 1);
    }

    public function takesAClosureOutsideOfAClassWithoutAWarning(): void
    {
        $listener = \Closure::bind(static function (Other $event) {}, null, null);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        });

        try {
            new Dispatcher([$listener]);
        } finally {
            restore_error_handler();
        }

        Assert::same($warnings, []);
    }

    /**
     * @return iterable<string, array{callable(never): void, ?string, string}>
     */
    public static function invalidListeners(): iterable
    {
        yield 'no parameter' => [static function () {}, null, ' must take the event as its first parameter.'];

        yield 'a nullable class' => [
            static function (?Other $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got ?Thesis\Dispatcher\Fixtures\Other.',
        ];

        yield 'a nullable object' => [
            static function (?object $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got ?object.',
        ];

        yield 'a union with null' => [
            static function (null|Other|Unrelated $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got Thesis\Dispatcher\Fixtures\Other|Thesis\Dispatcher\Fixtures\Unrelated|null.',
        ];

        yield 'a scalar' => [
            static function (int $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got int.',
        ];

        yield 'a union with a scalar' => [
            static function (Other|string $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got Thesis\Dispatcher\Fixtures\Other|string.',
        ];

        yield 'iterable' => [
            static function (iterable $event) {},
            'event',
            ' must be typed with classes, interfaces or object, got iterable.',
        ];

        yield 'a class that does not exist' => [
            // @phpstan-ignore class.notFound (a type that does not exist is the case)
            static function (Missing $event) {},
            'event',
            ' is typed with Thesis\Missing, which is not a class or an interface.',
        ];

        // @phpstan-ignore generator.valueType (a generator listener is the case)
        yield 'a generator' => [
            static function (Other $event) {
                yield;
            },
            null,
            ' must not be a generator: its body would not run.',
        ];

        yield 'a variadic parameter' => [
            static function (Other ...$events) {},
            'events',
            ' must take the event as a plain parameter, not a variadic one.',
        ];

        yield 'a parameter by reference' => [
            static function (Other &$event) {},
            'event',
            ' must take the event by value, not by reference.',
        ];

        // @phpstan-ignore generator.valueType (a listener with a second required parameter is the case)
        yield 'a second required parameter' => [
            static function (Other $event, int $times) {},
            'times',
            ' must be optional: a listener is called with the event only.',
        ];
    }

    /**
     * @param callable(never): void $listener
     * @param ?string $parameter null if the message names the function
     */
    #[DataProvider('invalidListeners')]
    public function rejectsAParameterThatCannotTakeAnEvent(callable $listener, ?string $parameter, string $error): void
    {
        $name = $parameter === null ? formatFunction($listener) : formatParameter($listener, $parameter);
        Expect::exception(\InvalidArgumentException::class)->withMessage($name . $error);

        new Dispatcher([$listener]);
    }
}
