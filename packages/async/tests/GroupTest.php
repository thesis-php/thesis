<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\Scope;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\thrown;

#[Test]
final class GroupTest
{
    public function returnsBodyResult(): void
    {
        Assert::same(group(static fn() => 'result'), 'result');
    }

    public function waitsForEveryTask(): void
    {
        $finished = [];

        group(static function (Group $group) use (&$finished) {
            foreach ([3, 1, 2] as $ms) {
                $group->spawn(static function () use ($ms, &$finished) {
                    delay($ms / 1_000);
                    $finished[] = $ms;
                });
            }
        });

        Assert::array($finished)->sameElementsAs([1, 2, 3]);
        assertNoCallbacksLeft();
    }

    public function runsTasksConcurrently(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                for ($i = 0; $i < 10; ++$i) {
                    $group->spawn(static fn() => delay(0.02));
                }
            });
        });

        Assert::numeric($elapsed)->lessThan(150);
    }

    public function taskFailureCancelsSiblingsAndBodyAndIsRethrownAfterJoin(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () {
                    delay(0.001);

                    throw new \RuntimeException('task failed');
                });
                $group->spawn(static function () use (&$events) {
                    try {
                        delay(1);
                    } catch (Cancelled $cancelled) {
                        $events[] = 'sibling cancelled';

                        throw $cancelled;
                    } finally {
                        shield(static fn() => delay(0.005));
                        $events[] = 'sibling cleaned up';
                    }
                });

                try {
                    delay(1);
                } catch (Cancelled $cancelled) {
                    $events[] = 'body cancelled';

                    throw $cancelled;
                }
            });
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'task failed');
        Assert::array($events)->sameElementsAs(['sibling cancelled', 'sibling cleaned up', 'body cancelled']);
    }

    public function firstFailureWins(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('first');

        group(static function (Group $group) {
            $group->spawn(static function () {
                delay(0.001);

                throw new \RuntimeException('first');
            });
            $group->spawn(static function () {
                try {
                    delay(1);
                } catch (Cancelled) {
                    throw new \LogicException('second, caused by the cancellation');
                }
            });
        });
    }

    public function bodyFailureCancelsTasksAndIsRethrown(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () use (&$events) {
                    try {
                        delay(1);
                    } catch (Cancelled $cancelled) {
                        $events[] = 'task cancelled';

                        throw $cancelled;
                    }
                });
                delay(0.001);

                throw new \LogicException('body failed');
            });
        });

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same($events, ['task cancelled']);
    }

    public function bodyFailureWinsOverLaterTaskFailure(): void
    {
        Expect::exception(\LogicException::class)->withMessage('body failed');

        group(static function (Group $group) {
            $group->spawn(static function () {
                try {
                    delay(1);
                } catch (Cancelled) {
                    throw new \RuntimeException('task failed on cancellation');
                }
            });
            delay(0.001);

            throw new \LogicException('body failed');
        });
    }

    public function cancelCancelsTasksButNotBody(): void
    {
        $events = [];

        $result = group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use (&$events) {
                try {
                    delay(1);
                } catch (Cancelled $cancelled) {
                    $events[] = 'task cancelled';

                    throw $cancelled;
                }
            });
            delay(0.001);

            $group->cancel();
            delay(0.001);

            return 'body result';
        });

        Assert::same($result, 'body result');
        Assert::same($events, ['task cancelled']);
    }

    public function cancelCancelsTasksStartedLater(): void
    {
        $started = false;

        group(static function (Group $group) use (&$started) {
            $group->cancel();
            $group->spawn(static function () use (&$started) {
                $started = true;
            });
        });

        Assert::false($started);
    }

    public function cancelledTaskDoesNotFailGroup(): void
    {
        $result = group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(1));
            delay(0.001);
            $task->cancel();

            return 'ok';
        });

        Assert::same($result, 'ok');
    }

    public function taskCancelledBeforeItStartedNeverRuns(): void
    {
        $started = false;

        group(static function (Group $group) use (&$started) {
            $group->spawn(static function () use (&$started) {
                $started = true;
            })->cancel();
        });

        Assert::false($started);
    }

    public function tasksMayStartTasksWhileGroupJoins(): void
    {
        $finished = false;

        group(static function (Group $group) use (&$finished) {
            $group->spawn(static function () use ($group, &$finished) {
                delay(0.001);
                $group->spawn(static function () use (&$finished) {
                    delay(0.001);
                    $finished = true;
                });
            });
        });

        Assert::true($finished);
    }

    public function spawnAfterGroupReturnedThrows(): void
    {
        $escaped = group(static fn(Group $group) => $group);

        Expect::exception(\LogicException::class)
            ->withMessage('The group is closed: a task can only be started until group() returns.');

        $escaped->spawn(static fn() => null);
    }

    public function daemonAfterGroupReturnedThrows(): void
    {
        $escaped = group(static fn(Group $group) => $group);

        Expect::exception(\LogicException::class);

        $escaped->daemon(static fn() => null);
    }

    public function daemonIsCancelledWhenBodyAndTasksAreDone(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->daemon(static function () use (&$events) {
                try {
                    delay(0.05);
                    $events[] = 'daemon finished by itself';
                } catch (Cancelled $cancelled) {
                    $events[] = 'daemon cancelled';

                    throw $cancelled;
                }
            });
            $group->spawn(static function () use (&$events) {
                delay(0.005);
                $events[] = 'task done';
            });
            $events[] = 'body done';
        });

        Assert::same($events, ['body done', 'task done', 'daemon cancelled']);
    }

    public function daemonStartedByTaskWhileJoiningIsCancelledToo(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use ($group, &$events) {
                $group->daemon(static function () use (&$events) {
                    try {
                        delay(0.05);
                    } catch (Cancelled $cancelled) {
                        $events[] = 'daemon cancelled';

                        throw $cancelled;
                    }
                });
                delay(0.001);
            });
        });

        Assert::same($events, ['daemon cancelled']);
    }

    public function daemonFailureFailsGroup(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('heartbeat failed');

        group(static function (Group $group) {
            $group->daemon(static function () {
                delay(0.001);

                throw new \RuntimeException('heartbeat failed');
            });

            delay(1);
        });
    }

    public function cancelCancelsDaemons(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->daemon(static function () use (&$events) {
                try {
                    delay(1);
                } catch (Cancelled $cancelled) {
                    $events[] = 'daemon cancelled';

                    throw $cancelled;
                }
            });
            delay(0.001);
            $group->cancel();
            delay(0.001);
            $events[] = 'body done';
        });

        Assert::same($events, ['daemon cancelled', 'body done']);
    }

    public function outerCancellationOfUnawaitedTaskIsNotSwallowed(): void
    {
        $exception = thrown(static function () {
            timeout(0.001, static fn() => group(static function (Group $group) {
                $group->spawn(static fn() => delay(1));

                return 'pretends to be done';
            }));
        });

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function outerCancellationOfDaemonOnlyDoesNotDiscardResult(): void
    {
        $result = timeout(0.001, static fn() => group(static function (Group $group) {
            $group->daemon(static fn() => delay(1));

            try {
                delay(1);
            } catch (Cancelled) {
            }

            return 'result';
        }));

        Assert::same($result, 'result');
    }

    public function taskFailureWinsOverOuterCancellation(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(0.001, static function () {
            group(static function (Group $group) {
                $group->spawn(static function () {
                    try {
                        delay(1);
                    } catch (Cancelled) {
                        throw new \RuntimeException('connection reset');
                    }
                });
            });
        });
    }

    public function bodyCancellationIsRethrownAfterJoin(): void
    {
        $taskFinished = false;

        $exception = thrown(static function () use (&$taskFinished) {
            timeout(0.001, static function () use (&$taskFinished) {
                group(static function (Group $group) use (&$taskFinished) {
                    $group->spawn(static function () use (&$taskFinished) {
                        shield(static fn() => delay(0.005));
                        $taskFinished = true;
                    });

                    delay(1);
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::true($taskFinished);
    }

    public function taskFailureWinsOverOuterCancellationOfWaitingBody(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(0.001, static function () {
            group(static function (Group $group) {
                $group->spawn(static function () {
                    try {
                        delay(1);
                    } catch (Cancelled) {
                        throw new \RuntimeException('connection reset');
                    }
                });

                delay(1); // gets Cancelled before the task fails
            });
        });
    }

    public function outerCancellationReportsFirstTaskCutShort(): void
    {
        $exception = thrown(static function () {
            timeout(0.001, static function () {
                group(static function (Group $group) {
                    $group->spawn(static fn() => suspend(static function (Continuation $continuation) {}));
                    $group->spawn(static fn() => delay(1));
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        $cancelled = $exception->getPrevious();
        Assert::instanceOf($cancelled, Cancelled::class);
        // the first task waited in suspend() directly, the second one through delay()
        Assert::notSame($cancelled->getTrace()[2]['function'] ?? null, 'Thesis\Async\delay');
    }

    public function everyTaskKeepsItsOwnScope(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                $first = $group->spawn(static function () {
                    delay(0.001); // meanwhile the second task enters its own scope
                    delay(1);     // still waits in the first task's scope
                });
                $group->spawn(static fn() => delay(0.001));
                delay(0.005);

                $first->cancel();
            });
        });

        Assert::numeric($elapsed)->lessThan(500);
    }

    public function taskScopeIsRestoredAfterNestedScope(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                $task = $group->spawn(static function () {
                    timeout(1, static fn() => null);
                    delay(1); // still waits in the task's scope
                });
                delay(0.001);

                $task->cancel();
            });
        });

        Assert::numeric($elapsed)->lessThan(500);
    }

    public function cancelledRethrownOutsideItsScopeFailsGroup(): void
    {
        $foreign = thrown(static fn() => timeout(0.001, static fn() => delay(1)))->getPrevious();
        Assert::instanceOf($foreign, Cancelled::class);

        $exception = thrown(static function () use ($foreign) {
            group(static function (Group $group) use ($foreign) {
                $group->spawn(static function () use ($foreign) {
                    throw $foreign;
                });
            });
        });

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same(
            $exception->getMessage(),
            'Cancelled escaped a task whose scope is not cancelled: it was caught elsewhere and rethrown.',
        );
        Assert::same($exception->getPrevious(), $foreign);
    }

    public function worksInsideFiber(): void
    {
        $nested = static function (Group $inner) {
            $inner->spawn(static fn() => delay(0.001));

            return 'nested';
        };

        $result = group(static fn(Group $outer) => $outer->spawn(static fn() => group($nested))->await());

        Assert::same($result, 'nested');
    }

    public function closesEveryScope(): void
    {
        /** @var \Closure(Scope): array<int, Scope> */
        $children = static fn(Scope $scope): array => (fn(): array => $this->children)->call($scope);
        $onlyChild = static function (Scope $scope) use ($children): Scope {
            $scopes = $children($scope);
            Assert::count($scopes, 1);
            $child = current($scopes);
            \assert($child instanceof Scope);

            return $child;
        };
        $root = Scope::current();

        group(static function (Group $group) use ($children, $onlyChild) {
            $body = Scope::current();
            timeout(1, static fn() => null);
            thrown(static fn() => timeout(0.001, static fn() => delay(1)));
            shield(static fn() => null);
            $group->spawn(static fn() => null);
            $group->spawn(static fn() => delay(1))->cancel();
            $group->daemon(static fn() => null);
            $group->spawn(static function () {
                timeout(1, static fn() => delay(0.001));
                shield(static fn() => delay(0.001));
            })->await();
            delay(0.001);

            $tasks = $onlyChild($body);
            $daemons = $onlyChild($tasks);
            Assert::same($children($daemons), []);
        });

        Assert::same($children($root), []);
    }
}
