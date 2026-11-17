<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Fixtures\Other;
use Thesis\Dispatcher\Fixtures\Subscriber;
use Thesis\Dispatcher\Fixtures\Unrelated;
use function Thesis\Formatter\formatClass;

#[Test]
final class ListenersOfTest
{
    public function takesTheMarkedMethodsInTheOrderOfTheClassThenTheInherited(): void
    {
        $subscriber = new Subscriber();
        $dispatcher = new Dispatcher(listenersOf($subscriber));

        $dispatcher->dispatch(new Other());
        $dispatcher->dispatch(new Unrelated());

        Assert::same($subscriber->calls, ['other', 'overridden', 'inherited', 'unrelated']);
    }

    public function takesAStaticMethod(): void
    {
        $subscriber = new class {
            /**
             * @var list<object>
             */
            public static array $calls = [];

            #[Listener]
            public static function onOther(Other $event): void
            {
                self::$calls[] = $event;
            }
        };
        $dispatcher = new Dispatcher(listenersOf($subscriber));
        $event = new Other();

        $dispatcher->dispatch($event);

        Assert::same($subscriber::$calls, [$event]);
    }

    public function leavesTheChecksOfAMethodToTheDispatcher(): void
    {
        $subscriber = new class {
            #[Listener]
            public function onNothing(): void {}
        };
        $listeners = listenersOf($subscriber);
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage(formatClass($subscriber) . '::onNothing() must take the event as its first parameter.')
        ;

        new Dispatcher($listeners);
    }

    public function rejectsAMarkedMethodThatIsNotPublic(): void
    {
        $subscriber = new class {
            // @phpstan-ignore method.unused, thesis.listenerNotPublic (a marked method that is not public is the case)
            #[Listener]
            private static function onOther(Other $event): void {}
        };
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage(formatClass($subscriber) . '::onOther() must be public to be a listener.')
        ;

        listenersOf($subscriber);
    }

    public function rejectsAnObjectWithoutMarkedMethods(): void
    {
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage('Thesis\Dispatcher\Fixtures\Other has no method marked #[Thesis\Dispatcher\Listener].')
        ;

        listenersOf(new Other());
    }
}
