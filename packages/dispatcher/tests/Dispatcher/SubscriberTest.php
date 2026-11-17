<?php

declare(strict_types=1);

namespace Thesis\Dispatcher;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Fixtures\Other;
use Thesis\Dispatcher\Fixtures\Unrelated;
use function Thesis\Formatter\formatClass;

#[Test]
final class SubscriberTest
{
    public function givesItsListenersInTheirOrderAmongTheOthers(): void
    {
        $subscriber = new class implements Subscriber {
            /**
             * @var list<string>
             */
            public array $calls = [];

            #[\Override]
            public function listeners(): iterable
            {
                yield $this->onUnrelated(...);
                yield $this->onOther(...);
                yield $this->onUnrelatedAgain(...);
            }

            private function onOther(Other $event): void
            {
                $this->calls[] = 'other';
            }

            private function onUnrelated(Unrelated $event): void
            {
                $this->calls[] = 'unrelated';
            }

            private function onUnrelatedAgain(Unrelated $event): void
            {
                $this->calls[] = 'unrelated again';
            }
        };
        $before = static function (Unrelated $event) use ($subscriber) {
            $subscriber->calls[] = 'before';
        };
        $after = static function (Unrelated $event) use ($subscriber) {
            $subscriber->calls[] = 'after';
        };
        $dispatcher = new Dispatcher([$before, $subscriber, $after]);

        $dispatcher->dispatch(new Unrelated());
        $dispatcher->dispatch(new Other());

        Assert::same($subscriber->calls, ['before', 'unrelated', 'unrelated again', 'after', 'other']);
    }

    public function isNotCalledItself(): void
    {
        $subscriber = new class implements Subscriber {
            /**
             * @var list<string>
             */
            public array $calls = [];

            public function __invoke(Other $event): void
            {
                $this->calls[] = 'invoked';
            }

            #[\Override]
            public function listeners(): iterable
            {
                yield $this->onOther(...);
            }

            private function onOther(Other $event): void
            {
                $this->calls[] = 'listener';
            }
        };
        $dispatcher = new Dispatcher([$subscriber]);

        $dispatcher->dispatch(new Other());

        Assert::same($subscriber->calls, ['listener']);
    }

    public function mayHaveNoListeners(): void
    {
        $subscriber = new class implements Subscriber {
            #[\Override]
            public function listeners(): iterable
            {
                return [];
            }
        };
        $dispatcher = new Dispatcher([$subscriber]);

        Assert::false($dispatcher->listens(Other::class));
    }

    public function leavesItsListenersToTheChecksOfAnyListener(): void
    {
        $subscriber = new class implements Subscriber {
            #[\Override]
            public function listeners(): iterable
            {
                return [self::onNothing(...)];
            }

            private static function onNothing(): void {}
        };
        Expect
            ::exception(\InvalidArgumentException::class)
            ->withMessage(formatClass($subscriber) . '::onNothing() must take the event as its first parameter.')
        ;

        new Dispatcher([$subscriber]);
    }
}
