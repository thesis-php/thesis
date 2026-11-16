<?php

declare(strict_types=1);

namespace Thesis;

use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class ExceptionallyTest
{
    /**
     * @var list<string>
     */
    private array $passedOn = [];

    /**
     * @var \Closure(int, string): true
     */
    private readonly \Closure $previous;

    public function __construct()
    {
        $this->previous = function (int $_level, string $message) {
            $this->passedOn[] = $message;

            return true;
        };
    }

    #[BeforeTest]
    public function installPrevious(): void
    {
        $this->passedOn = [];
        set_error_handler($this->previous);
    }

    #[AfterTest]
    public function restorePrevious(): void
    {
        restore_error_handler();
    }

    public function returnsTheValue(): void
    {
        $value = exceptionally(static fn() => 42);

        Assert::same($value, 42);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function throwsAnError(): void
    {
        Expect::exception(\ErrorException::class)->withMessage('boom');

        exceptionally(static fn() => trigger_error('boom', E_USER_WARNING));
    }

    public function keepsTheSeverityAndThePlace(): void
    {
        $line = __LINE__ + 1;
        $error = self::errorOf(static fn() => exceptionally(static fn() => trigger_error('boom', E_USER_NOTICE)));

        Assert::same($error->getSeverity(), E_USER_NOTICE);
        Assert::same($error->getFile(), __FILE__);
        Assert::same($error->getLine(), $line);
    }

    public function restoresThePreviousHandlerAfterAnError(): void
    {
        self::errorOf(static fn() => exceptionally(static fn() => trigger_error('boom', E_USER_WARNING)));

        Assert::same(get_error_handler(), $this->previous);
    }

    public function passesASuppressedErrorOn(): void
    {
        exceptionally(static fn() => @trigger_error('suppressed', E_USER_WARNING));

        Assert::same($this->passedOn, ['suppressed']);
    }

    public function passesDeprecationsOnByDefault(): void
    {
        exceptionally(static fn() => deprecate('deprecated'));

        Assert::same($this->passedOn, ['deprecated']);
    }

    public function throwsALevelGiven(): void
    {
        Expect::exception(\ErrorException::class)->withMessage('deprecated');

        exceptionally(static fn() => deprecate('deprecated'), E_ALL);
    }

    public function passesOnALevelNotGiven(): void
    {
        exceptionally(static fn() => trigger_error('notice', E_USER_NOTICE), E_USER_WARNING);

        Assert::same($this->passedOn, ['notice']);
    }

    public function appliesTheLevelsOfTheInnermostCall(): void
    {
        $error = self::errorOf(static fn() => exceptionally(static function () {
            exceptionally(static fn() => trigger_error('inner', E_USER_NOTICE), E_USER_WARNING);
            trigger_error('outer', E_USER_NOTICE);
        }));

        Assert::same($error->getMessage(), 'outer');
        Assert::same($this->passedOn, ['inner']);
    }

    public function passesOnAnErrorOfAnotherFiberWhileSuspended(): void
    {
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();

        trigger_error('main', E_USER_WARNING);
        $fiber->resume();

        Assert::same($this->passedOn, ['main']);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function throwsInTheFiberThatFinishesLast(): void
    {
        $first = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $last = new \Fiber(static fn() => exceptionally(static function () {
            \Fiber::suspend();
            trigger_error('last', E_USER_WARNING);
        }));
        $first->start();
        $last->start();
        $first->resume();

        $error = self::errorOf($last->resume(...));

        Assert::same($error->getMessage(), 'last');
        Assert::same(get_error_handler(), $this->previous);
    }

    public function passesOnPastTheHandlersOfOtherCalls(): void
    {
        $depths = [];
        set_error_handler(static function () use (&$depths) {
            $depths[] = \count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

            return true;
        });
        $suspended = [];

        for ($calls = 1; $calls <= 3; ++$calls) {
            $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
            $fiber->start();
            $suspended[] = $fiber;
            trigger_error('main', E_USER_WARNING);
        }

        foreach ($suspended as $fiber) {
            $fiber->resume();
        }

        restore_error_handler();

        Assert::same(\count($depths), 3);
        Assert::same(\count(array_unique($depths)), 1);
    }

    public function throwsWhileAnotherFiberIsSuspendedInSuppression(): void
    {
        $fiber = new \Fiber(static fn() => @\Fiber::suspend());
        $fiber->start();

        Expect::exception(\ErrorException::class)->withMessage('main');

        exceptionally(static fn() => trigger_error('main', E_USER_WARNING));
    }

    public function passesOnAnErrorOfAFiberStartedInside(): void
    {
        exceptionally(static fn() => new \Fiber(static fn() => trigger_error('child', E_USER_WARNING))->start());

        Assert::same($this->passedOn, ['child']);
    }

    public function fiberSuspendedInsideGoesAwayWithLastReference(): void
    {
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();
        $reference = \WeakReference::create($fiber);
        unset($fiber);

        Assert::null($reference->get());
        Assert::same(get_error_handler(), $this->previous);
    }

    public function keepsAHandlerSetWhileSuspended(): void
    {
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();
        $late = static fn() => true;
        set_error_handler($late);

        $fiber->resume();

        Assert::same(get_error_handler(), $late);
        restore_error_handler();
        exceptionally(static fn() => null);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function keepsAHandlerSetInsideTheFunction(): void
    {
        $late = static fn() => true;

        exceptionally(static fn() => set_error_handler($late));

        Assert::same(get_error_handler(), $late);
        restore_error_handler();
        exceptionally(static fn() => null);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function throwsAboveAHandlerOfOtherCodeAfterAnotherFiberFinishes(): void
    {
        $swallow = static fn() => true;
        $first = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $nested = new \Fiber(static fn() => exceptionally(static function () use ($swallow) {
            set_error_handler($swallow);

            try {
                exceptionally(static function () {
                    \Fiber::suspend();
                    trigger_error('nested', E_USER_WARNING);
                });
            } finally {
                restore_error_handler();
            }
        }));
        $first->start();
        $nested->start();
        $first->resume();

        $error = self::errorOf($nested->resume(...));

        Assert::same($error->getMessage(), 'nested');
        Assert::same(get_error_handler(), $this->previous);
    }

    public function keepsTheHandlerBelowAfterItsOwnWasRestoredByOtherCode(): void
    {
        $below = errorHandlers();
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();
        restore_error_handler();

        $fiber->resume();

        Assert::same(errorHandlers(), $below);
    }

    public function keepsTheHandlerThatRunsWhileAFiberIsDestroyed(): void
    {
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();
        $destroy = static function () use (&$fiber) {
            $fiber = null;

            return true;
        };
        set_error_handler($destroy);

        trigger_error('destroy', E_USER_WARNING);

        Assert::same(get_error_handler(), $destroy);
        restore_error_handler();
        exceptionally(static fn() => null);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function throwsInsideAHandler(): void
    {
        $thrown = [];
        set_error_handler(static function () use (&$thrown) {
            try {
                exceptionally(static fn() => trigger_error('inside', E_USER_WARNING));
            } catch (\ErrorException $error) {
                $thrown[] = $error->getMessage();
            }

            return true;
        });
        $fiber = new \Fiber(static fn() => exceptionally(\Fiber::suspend(...)));
        $fiber->start();
        $before = errorHandlers();

        trigger_error('outside', E_USER_WARNING);

        Assert::same($thrown, ['inside']);
        Assert::same(errorHandlers(), $before);
        $fiber->resume();
        restore_error_handler();
        Assert::same(get_error_handler(), $this->previous);
    }

    public function setsNoMoreHandlersThanCallsThatRun(): void
    {
        $loop = static function () {
            for ($calls = 0; $calls <= 10; ++$calls) {
                exceptionally(\Fiber::suspend(...));
            }
        };
        $first = new \Fiber($loop);
        $second = new \Fiber($loop);
        $first->start();
        $second->start();
        $handlers = \count(errorHandlers());

        for ($i = 0; $i < 10; ++$i) {
            $first->resume();
            $second->resume();
        }

        Assert::same(\count(errorHandlers()), $handlers);
        unset($first, $second);
        Assert::same(get_error_handler(), $this->previous);
    }

    public function takesItsHandlerOffWhenAnAbandonedFiberIsCollected(): void
    {
        new \Fiber(static function () {
            $cycle = \Fiber::getCurrent();
            exceptionally(\Fiber::suspend(...));
        })->start();

        gc_collect_cycles();

        Assert::same(get_error_handler(), $this->previous);
    }

    /**
     * The collector starts when the buffer of roots is full, at any release of a value: here inside exceptionally(),
     * at the first, the second, ... release after the buffer was filled. It destroys the abandoned fiber there.
     */
    public function keepsTheHandlerBelowWhenTheCollectorRunsInside(): void
    {
        for ($free = 0; $free < 24; ++$free) {
            gc_collect_cycles();
            new \Fiber(static function () {
                $cycle = \Fiber::getCurrent();
                exceptionally(\Fiber::suspend(...));
            })->start();
            $roots = [];
            $status = gc_status();

            for ($missing = $status['threshold'] - $status['roots'] - $free; $missing > 0; --$missing) {
                $roots[] = $root = new \stdClass();
                unset($root);
            }

            exceptionally(static fn() => null);
            gc_collect_cycles();
            exceptionally(static fn() => null);

            Assert::same(get_error_handler(), $this->previous);
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function errorOf(callable $fn): \ErrorException
    {
        try {
            $fn();
        } catch (\ErrorException $error) {
            return $error;
        }

        Assert::fail('No \ErrorException was thrown.');
    }
}
