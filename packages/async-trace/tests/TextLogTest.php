<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Continuation;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\Suspended;
use Thesis\Dispatcher;
use function Thesis\Async\all;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\suspend;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\AsyncTrace\Tests\unheardEvents;
use function Thesis\Dispatcher\listenersOf;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;

#[Test]
final class TextLogTest
{
    public function writesLifecycleOfTask(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));

                return 42;
            });
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #1 joining',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\delay',
            '1 #2 resumed',
            '1 #2 returned 42',
            '1 #1 returned null',
        ]);
    }

    public function writesNamesAsLiterals(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => 'user', name: 'fetch user');
            $group->daemon(static fn() => null, name: 'heartbeat');
        }, name: 'checkout'));

        Assert::same(\array_slice($log->lines, 0, 3), [
            "0 #1 group 'checkout' in root",
            "0 #2 task 'fetch user' in #1",
            "0 #3 daemon 'heartbeat' in #1",
        ]);
        Assert::contains($log->lines, "0 #2 returned 'user'");
    }

    public function writesFunctionThatCreatesGroup(): void
    {
        $log = self::record(static fn() => all(['user' => static fn() => null]));

        Assert::same($log->lines[0] ?? null, '0 #1 all in root');
        Assert::same($log->lines[1] ?? null, "0 #2 task 'user' in #1");
    }

    public function writesTimeAndDurationInExactSeconds(): void
    {
        $log = self::record(static fn() => timeoutOr(ms(1500), static fn() => delay(ns(1_000_000_001))));

        Assert::same($log->lines, [
            '0 #1 timeout 1.5s in root',
            '0 #1 suspended in Thesis\Async\delay',
            '1.000000001 #1 resumed',
            '1.000000001 #1 returned null',
        ]);
    }

    public function writesNegativeDurationWithSign(): void
    {
        $log = self::record(static fn() => timeoutOr(ms(-1500), static fn() => null));

        Assert::same($log->lines[0] ?? null, '0 #1 timeout -1.5s in root');
    }

    public function writesCancellationByDeadlineReachingSubtree(): void
    {
        $log = self::record(static fn() => timeoutOr(sec(2), static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
        })));

        Assert::same($log->lines, [
            '0 #1 timeout 2s in root',
            '0 #2 group in #1',
            '0 #3 task in #2',
            '0 #2 joining',
            '0 #3 started',
            '0 #3 suspended in Thesis\Async\delay',
            '2 #1 cancelled: Deadline by #1, reached #1 #2 #3',
            '2 #3 resumed by Cancelled',
            '2 #3 threw Cancelled',
            '2 #2 threw Cancelled',
            '2 #1 threw Cancelled',
        ]);
    }

    public function writesFailureCancellingGroup(): void
    {
        $log = self::record(static fn() => self::thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
            $group->spawn(static function () {
                delay(sec(1));

                throw new \RuntimeException('boom');
            });
        })));

        Assert::same(\array_slice($log->lines, 8), [
            '1 #3 resumed',
            '1 #3 threw RuntimeException',
            '1 #1 cancelled: Failure by #3, reached #1 #2',
            '1 #2 resumed by Cancelled',
            '1 #2 threw Cancelled',
            '1 #1 threw RuntimeException',
        ]);
    }

    public function writesCallThatHasCancelled(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)))->cancel();
            $group->spawn(static fn() => delay(sec(5)));
            $group->cancelTasks();
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #2 cancelled: Handle by #1 in Thesis\Async\Task::cancel, reached #2',
            '0 #3 task in #1',
            '0 #1 cancelled: CancelTasks by #1 in Thesis\Async\Group::cancelTasks, reached #3',
            '0 #1 joining',
            '0 #2 threw Cancelled',
            '0 #3 threw Cancelled',
            '0 #1 returned null',
        ]);
    }

    public function writesShieldAndScopeCreatedCancelled(): void
    {
        $log = self::record(static fn() => timeoutOr(sec(0), static function () {
            group(static fn() => shield(static fn() => null));
        }));

        Assert::same($log->lines, [
            '0 #1 timeout 0s in root',
            '0 #1 cancelled: Deadline by #1, reached #1',
            '0 #2 group in #1, cancelled',
            '0 #3 shield in #2',
            '0 #3 returned null',
            '0 #2 joining',
            '0 #2 returned null',
            '0 #1 returned null',
        ]);
    }

    public function writesWaitResumedWithError(): void
    {
        $log = self::record(static fn() => self::thrown(static fn() => shield(static fn() => suspend(
            /** @param Continuation<null> $continuation */
            static function (Continuation $continuation) {
                $continuation->throw(new \LogicException());
            },
        ))));

        Assert::same($log->lines, [
            '0 #1 shield in root',
            '0 #1 suspended in Thesis\Async\suspend',
            '0 #1 resumed by LogicException',
            '0 #1 threw LogicException',
        ]);
    }

    public function writesWaitWithUnknownCaller(): void
    {
        $log = new TextLog();

        $log->onSuspended(new Suspended(scope: 1, at: Instant::now(), caller: null));

        Assert::same($log->lines, ['0 #1 suspended']);
    }

    public function takesNoEventOfNewerAsyncItDoesNotKnow(): void
    {
        Assert::false(new Dispatcher(listenersOf(new TextLog()))->listens(Event::class));
    }

    public function listensToEveryEventOfAsync(): void
    {
        Assert::same(unheardEvents(new TextLog()), []);
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function thrown(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    /**
     * @param callable(): mixed $program
     */
    private static function record(callable $program): TextLog
    {
        $log = new TextLog();
        observe(new Dispatcher(listenersOf($log)), $program);

        return $log;
    }
}
