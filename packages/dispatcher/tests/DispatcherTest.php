<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

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
        yield 'a class' => [static function (Base $event): void {}, [Base::class, Child::class]];
        yield 'a final class' => [static function (Child $event): void {}, [Child::class]];
        yield 'an interface' => [static function (Event $event): void {}, [Base::class, Child::class, Other::class]];
        yield 'a union' => [static function (Other|Unrelated $event): void {}, [Other::class, Unrelated::class]];
        yield 'an intersection' => [static function (Event&Named $event): void {}, [Child::class]];
        yield 'a DNF' => [static function ((Event&Named)|Unrelated $event): void {}, [Child::class, Unrelated::class]];
        yield 'object' => [static function (object $event): void {}, self::ALL];
        yield 'mixed' => [static function (mixed $event): void {}, self::ALL];
        yield 'no type' => [static function ($event): void {}, self::ALL];
        yield 'self' => [new Child()->onSelf(...), [Base::class, Child::class]];
        yield 'parent' => [new Child()->onParent(...), [Base::class, Child::class]];
    }
    private const array ALL = [Base::class, Child::class, Other::class, Unrelated::class];

    /**
     * @param callable(never): void $listener
     * @param list<class-string> $classes
     */
    #[DataProvider('listenersByType')]
    public function getsTheEventsOfTheTypeOfItsParameter(callable $listener, array $classes): void
    {
        $dispatcher = new Dispatcher($listener);

        Assert::same(array_values(array_filter(self::ALL, $dispatcher->listens(...))), $classes);
    }

    public function callsTheListenersInTheOrderTheyAreGiven(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher(
            static function (Event $event) use (&$calls): void {
                $calls[] = 'event';
            },
            static function (Child $event) use (&$calls): void {
                $calls[] = 'child';
            },
            static function (object $event) use (&$calls): void {
                $calls[] = 'object';
            },
            static function (Unrelated $event) use (&$calls): void {
                $calls[] = 'unrelated';
            },
        );

        $dispatcher->dispatch(new Child());
        $dispatcher->dispatch(new Other());

        Assert::same($calls, ['event', 'child', 'object', 'event', 'object']);
    }

    public function callsAListenerOnceForAnEventOfSeveralTypesOfItsUnion(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher(static function (Named|Event|Base $event) use (&$calls): void {
            $calls[] = $event;
        });
        $event = new Child();

        $dispatcher->dispatch($event);
        $dispatcher->dispatch($event);

        Assert::same($calls, [$event, $event]);
    }

    public function passesTheEvent(): void
    {
        $listener = new Child();
        $dispatcher = new Dispatcher($listener->onSelf(...), $listener->onSelf(...));

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
        $dispatcher = new Dispatcher($listener);
        $event = new Other();

        $dispatcher->dispatch($event);

        Assert::same($listener->calls, [$event]);
    }

    public function takesAListenerWithOptionalParametersAfterTheEvent(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher(static function (Other $event, int $times = 2) use (&$calls): void {
            $calls[] = $times;
        });

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
        $dispatcher = new Dispatcher(
            static function (Other $event): void {
                throw new \RuntimeException('Failed.');
            },
            static function (Other $event) use (&$calls): void {
                $calls[] = $event;
            },
        );

        try {
            $dispatcher->dispatch(new Other());
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'Failed.');
        }

        Assert::same($calls, []);
    }

    public function dispatchesFromAListener(): void
    {
        $calls = [];
        $dispatcher = null;
        $dispatcher = new Dispatcher(
            static function (Other $event) use (&$dispatcher, &$calls): void {
                $calls[] = Other::class;
                $dispatcher?->dispatch(new Unrelated());
            },
            static function (Unrelated $event) use (&$calls): void {
                $calls[] = Unrelated::class;
            },
        );

        $dispatcher->dispatch(new Other());

        Assert::same($calls, [Other::class, Unrelated::class]);
    }

    public function letsAListenerSuspendTheFiber(): void
    {
        $calls = [];
        $dispatcher = new Dispatcher(
            static function (Other $event) use (&$calls): void {
                $calls[] = \Fiber::suspend('suspended');
            },
            static function (Other $event) use (&$calls): void {
                $calls[] = 'after';
            },
        );
        $fiber = new \Fiber($dispatcher->dispatch(...));

        Assert::same($fiber->start(new Other()), 'suspended');
        Assert::same($calls, []);

        $fiber->resume('resumed');

        Assert::true($fiber->isTerminated());
        Assert::same($calls, ['resumed', 'after']);
    }

    public function resolvesAClassLoadedAfterTheListenerIsGiven(): void
    {
        $dispatcher = new Dispatcher(static function (Event $event): void {});
        $event = new class implements Event {};

        Assert::true($dispatcher->listens($event::class));
    }

    /**
     * @return iterable<string, array{callable(never): void, ?string, string}>
     */
    public static function invalidListeners(): iterable
    {
        yield 'no parameter' => [static function (): void {}, null, ' must take the event as its first parameter.'];

        yield 'a nullable class' => [
            static function (?Other $event): void {},
            'event',
            ' must be typed with classes, interfaces or object, got ?Thesis\Dispatcher\Fixtures\Other.',
        ];

        yield 'a union with null' => [
            static function (null|Other|Unrelated $event): void {},
            'event',
            ' must be typed with classes, interfaces or object, got Thesis\Dispatcher\Fixtures\Other|Thesis\Dispatcher\Fixtures\Unrelated|null.',
        ];

        yield 'a scalar' => [
            static function (int $event): void {},
            'event',
            ' must be typed with classes, interfaces or object, got int.',
        ];

        yield 'a union with a scalar' => [
            static function (Other|string $event): void {},
            'event',
            ' must be typed with classes, interfaces or object, got Thesis\Dispatcher\Fixtures\Other|string.',
        ];

        yield 'iterable' => [
            static function (iterable $event): void {},
            'event',
            ' must be typed with classes, interfaces or object, got iterable.',
        ];

        yield 'a class that does not exist' => [
            // @phpstan-ignore class.notFound (a type that does not exist is the case)
            static function (Missing $event): void {},
            'event',
            ' is typed with Thesis\Dispatcher\Missing, which is not a class or an interface.',
        ];

        yield 'a variadic parameter' => [
            static function (Other ...$events): void {},
            'events',
            ' must take the event as a plain parameter, not a variadic one.',
        ];

        yield 'a parameter by reference' => [
            static function (Other &$event): void {},
            'event',
            ' must take the event by value, not by reference.',
        ];

        // @phpstan-ignore generator.valueType (a listener with a second required parameter is the case)
        yield 'a second required parameter' => [
            static function (Other $event, int $times): void {},
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

        new Dispatcher($listener);
    }
}
