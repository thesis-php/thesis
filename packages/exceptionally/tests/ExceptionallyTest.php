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

    /**
     * @param callable(): mixed $function
     */
    private static function errorOf(callable $function): \ErrorException
    {
        try {
            $function();
        } catch (\ErrorException $error) {
            return $error;
        }

        Assert::fail('No \ErrorException was thrown.');
    }
}
