<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\Scope;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\later;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

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
            foreach ([3, 1, 2] as $seconds) {
                $group->spawn(static function () use ($seconds, &$finished) {
                    delay(sec($seconds));
                    $finished[] = $seconds;
                });
            }
        });

        Assert::same($finished, [1, 2, 3]);
        assertNoCallbacksLeft();
    }

    public function runsTasksConcurrently(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                for ($i = 0; $i < 10; ++$i) {
                    $group->spawn(static fn() => delay(sec(20)));
                }
            });
        });

        Assert::same($elapsed, 20.0);
    }

    public function taskFailureCancelsSiblingsAndBodyAndIsRethrownAfterJoin(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () {
                    delay(sec(1));

                    throw new \RuntimeException('task failed');
                });
                $group->spawn(static function () use (&$events) {
                    try {
                        never();
                    } catch (Cancelled $cancelled) {
                        $events[] = 'sibling cancelled';

                        throw $cancelled;
                    } finally {
                        shield(static fn() => delay(sec(5)));
                        $events[] = 'sibling cleaned up';
                    }
                });

                try {
                    never();
                } catch (Cancelled $cancelled) {
                    $events[] = 'body cancelled';

                    throw $cancelled;
                }
            });
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'task failed');
        Assert::same($events, ['body cancelled', 'sibling cancelled', 'sibling cleaned up']);
    }

    public function firstFailureWins(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('first');

        group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));

                throw new \RuntimeException('first');
            });
            $group->spawn(static function () {
                try {
                    never();
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
                        never();
                    } catch (Cancelled $cancelled) {
                        $events[] = 'task cancelled';

                        throw $cancelled;
                    }
                });
                delay(sec(1));

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
                    never();
                } catch (Cancelled) {
                    throw new \RuntimeException('task failed on cancellation');
                }
            });
            delay(sec(1));

            throw new \LogicException('body failed');
        });
    }

    public function cancelTasksCancelsTasksButNotBody(): void
    {
        $events = [];

        $result = group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use (&$events) {
                try {
                    never();
                } catch (Cancelled $cancelled) {
                    $events[] = 'task cancelled';

                    throw $cancelled;
                }
            });
            delay(sec(1));

            $group->cancelTasks();
            delay(sec(1));

            return 'body result';
        });

        Assert::same($result, 'body result');
        Assert::same($events, ['task cancelled']);
    }

    public function cancelTasksCancelsTasksStartedLater(): void
    {
        $started = false;

        $result = group(static function (Group $group) use (&$started) {
            $group->cancelTasks();
            $handle = $group->spawn(static function () use (&$started) {
                $started = true;
            });
            delay(sec(0)); // the task would have started here

            Assert::true($handle->isDone);
            $awaited = thrown(static fn() => $handle->await());
            Assert::instanceOf($awaited, TaskCancelled::class);

            return 'body result';
        });

        Assert::false($started);
        Assert::same($result, 'body result');
    }

    public function cancelledTaskDoesNotFailGroup(): void
    {
        $result = group(static function (Group $group) {
            $handle = $group->spawn(never(...));
            delay(sec(1));
            $handle->cancel();

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
                delay(sec(1));
                $group->spawn(static function () use (&$finished) {
                    delay(sec(1));
                    $finished = true;
                });
            });
        });

        Assert::true($finished);
    }

    public function spawnAfterGroupReturnedThrows(): void
    {
        $escaped = group(static fn(Group $group) => $group);

        Expect
            ::exception(\LogicException::class)
            ->withMessage('The group is closed: a task can only be started until group() returns.')
        ;

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
                    delay(sec(50));
                    $events[] = 'daemon finished by itself';
                } catch (Cancelled $cancelled) {
                    $events[] = 'daemon cancelled';

                    throw $cancelled;
                }
            });
            $group->spawn(static function () use (&$events) {
                delay(sec(5));
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
                        delay(sec(50));
                    } catch (Cancelled $cancelled) {
                        $events[] = 'daemon cancelled';

                        throw $cancelled;
                    }
                });
                delay(sec(1));
            });
        });

        Assert::same($events, ['daemon cancelled']);
    }

    public function daemonFailureFailsGroup(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('heartbeat failed');

        group(static function (Group $group) {
            $group->daemon(static function () {
                delay(sec(1));

                throw new \RuntimeException('heartbeat failed');
            });

            never();
        });
    }

    public function cancelTasksCancelsDaemons(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->daemon(static function () use (&$events) {
                try {
                    never();
                } catch (Cancelled $cancelled) {
                    $events[] = 'daemon cancelled';

                    throw $cancelled;
                }
            });
            delay(sec(1));
            $group->cancelTasks();
            delay(sec(1));
            $events[] = 'body done';
        });

        Assert::same($events, ['daemon cancelled', 'body done']);
    }

    public function outerCancellationOfUnawaitedTaskIsNotSwallowed(): void
    {
        $exception = thrown(static function () {
            timeout(sec(1), static fn() => group(static function (Group $group) {
                $group->spawn(never(...));

                return 'pretends to be done';
            }));
        });

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function outerCancellationOfDaemonOnlyDoesNotDiscardResult(): void
    {
        $result = timeout(sec(1), static fn() => group(static function (Group $group) {
            $group->daemon(never(...));

            untilCancelled();

            return 'result';
        }));

        Assert::same($result, 'result');
    }

    public function outerCancellationOfTaskCancelledByHandleDoesNotDiscardResult(): void
    {
        $result = timeout(sec(2), static fn() => group(static function (Group $group) {
            $handle = $group->spawn(static function () {
                try {
                    never();
                } finally {
                    shield(static fn() => delay(sec(3)));
                }
            });

            delay(sec(1));
            $handle->cancel();

            return 'result';
        }));

        Assert::same($result, 'result');
    }

    public function outerCancellationOfTasksCancelledByGroupDoesNotDiscardResult(): void
    {
        $result = timeout(sec(2), static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                try {
                    never();
                } finally {
                    shield(static fn() => delay(sec(3)));
                }
            });

            delay(sec(1));
            $group->cancelTasks();

            return 'result';
        }));

        Assert::same($result, 'result');
    }

    public function outerCancellationOfTaskCancelledByHandleAfterItStillThrows(): void
    {
        Expect::exception(TimedOut::class);

        timeout(sec(1), static fn() => group(static function (Group $group) {
            $handle = $group->spawn(never(...));

            untilCancelled();
            $handle->cancel();

            return 'result';
        }));
    }

    public function outerCancellationOfTasksCancelledByGroupAfterItStillThrows(): void
    {
        Expect::exception(TimedOut::class);

        timeout(sec(1), static fn() => group(static function (Group $group) {
            $group->spawn(never(...));

            untilCancelled();
            $group->cancelTasks();

            return 'result';
        }));
    }

    public function taskFailureWinsOverOuterCancellation(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(sec(1), static function () {
            group(static function (Group $group) {
                $group->spawn(static function () {
                    try {
                        never();
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
            timeout(sec(1), static function () use (&$taskFinished) {
                group(static function (Group $group) use (&$taskFinished) {
                    $group->spawn(static function () use (&$taskFinished) {
                        shield(static fn() => delay(sec(5)));
                        $taskFinished = true;
                    });

                    never();
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::true($taskFinished);
    }

    public function taskFailureWinsOverOuterCancellationOfWaitingBody(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(sec(1), static function () {
            group(static function (Group $group) {
                $group->spawn(static function () {
                    try {
                        never();
                    } catch (Cancelled) {
                        throw new \RuntimeException('connection reset');
                    }
                });

                never(); // gets Cancelled before the task fails
            });
        });
    }

    public function outerCancellationReportsFirstTaskCutShort(): void
    {
        /** @var ?Cancelled $first */
        $first = null;

        $exception = thrown(static function () use (&$first) {
            timeout(sec(1), static function () use (&$first) {
                group(static function (Group $group) use (&$first) {
                    // spawned first, but cut short last: it unwinds after a cleanup
                    $group->spawn(static function () {
                        try {
                            never();
                        } finally {
                            shield(static fn() => delay(sec(1)));
                        }
                    });
                    $group->spawn(static function () use (&$first) {
                        try {
                            never();
                        } catch (Cancelled $cancelled) {
                            $first = $cancelled;

                            throw $cancelled;
                        }
                    });
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::instanceOf($first, Cancelled::class);
        Assert::same($exception->getPrevious(), $first);
    }

    public function everyTaskKeepsItsOwnScope(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                $first = $group->spawn(static function () {
                    delay(sec(1)); // meanwhile the second task enters its own scope
                    never(); // still waits in the first task's scope
                });
                $group->spawn(static fn() => delay(sec(1)));
                delay(sec(5));

                $first->cancel();
            });
        });

        Assert::same($elapsed, 5.0);
    }

    public function taskScopeIsRestoredAfterNestedScope(): void
    {
        $elapsed = elapsed(static function () {
            group(static function (Group $group) {
                $handle = $group->spawn(static function () {
                    timeout(sec(1000), static fn() => null);
                    never(); // still waits in the task's scope
                });
                delay(sec(1));

                $handle->cancel();
            });
        });

        Assert::same($elapsed, 1.0);
    }

    public function cancelledRethrownOutsideItsScopeFailsGroup(): void
    {
        $foreign = thrown(static fn() => timeout(sec(1), never(...)))->getPrevious();
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
            actual: $exception->getMessage(),
            expected: 'Cancelled escaped a task whose scope is not cancelled: it was caught elsewhere and rethrown.',
        );
        Assert::same($exception->getPrevious(), $foreign);
    }

    public function cancelledRethrownOutsideItsScopeFromBodyFailsGroup(): void
    {
        $foreign = thrown(static fn() => timeout(sec(1), never(...)))->getPrevious();
        Assert::instanceOf($foreign, Cancelled::class);
        $events = [];

        $exception = thrown(static function () use ($foreign, &$events) {
            group(static function (Group $group) use ($foreign, &$events) {
                $group->spawn(static function () use (&$events) {
                    try {
                        never();
                    } catch (Cancelled $cancelled) {
                        $events[] = 'task cancelled';

                        throw $cancelled;
                    }
                });
                delay(sec(1));

                throw $foreign;
            });
        });

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same(
            actual: $exception->getMessage(),
            expected: 'Cancelled escaped a group body whose scope is not cancelled: it was caught elsewhere and rethrown.',
        );
        Assert::same($exception->getPrevious(), $foreign);
        Assert::same($events, ['task cancelled']);
    }

    public function foreignCallbackFailureInMainCancelsAndJoinsTasks(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () use (&$events) {
                    try {
                        never();
                    } catch (Cancelled $cancelled) {
                        $events[] = 'task cancelled';

                        throw $cancelled;
                    } finally {
                        shield(static fn() => delay(sec(1)));
                        $events[] = 'task cleaned up';
                    }
                });
                // The loop throws it into the wait of its own fiber, that of the test: the join.
                later(sec(0), static function () {
                    throw new \RuntimeException('foreign');
                });
            });
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'foreign');
        Assert::same($events, ['task cancelled', 'task cleaned up']);
        assertNoCallbacksLeft();
    }

    public function foreignCallbackFailureInMainDuringFailingJoinKeepsJoining(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () use (&$events) {
                    try {
                        never();
                    } finally {
                        later(sec(0), static function () {
                            throw new \RuntimeException('foreign');
                        });
                        shield(static fn() => delay(sec(1)));
                        $events[] = 'task cleaned up';
                    }
                });
                delay(sec(0));

                throw new \LogicException('body failed');
            });
        });

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same($exception->getMessage(), 'body failed');
        Assert::same($events, ['task cleaned up']);
    }

    public function deadlockInMainCancelsTasksAndIsRethrown(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            group(static function (Group $group) use (&$events) {
                $group->spawn(static function () use (&$events) {
                    try {
                        new Deferred()->await(); // never completed: the event loop runs dry
                    } catch (Cancelled $cancelled) {
                        $events[] = 'task cancelled';

                        throw $cancelled;
                    }
                });
            });
        });

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same($events, ['task cancelled']);
    }

    public function deadlockInMainUnderShieldIsRethrownInsteadOfHanging(): void
    {
        /** @var Deferred<null> */
        $never = new Deferred();
        $escaped = null;
        $root = Scope::current();

        $exception = thrown(static function () use ($never, &$escaped) {
            group(static function (Group $group) use ($never, &$escaped) {
                $escaped = $group;
                $group->spawn(static fn() => shield($never->await(...)));
            });
        });

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same(self::children($root), []);
        /** @var Group $escaped */
        Assert::instanceOf(thrown(static fn() => $escaped->spawn(static fn() => null)), \LogicException::class);

        // let the orphaned task finish
        $never->complete();
        delay(sec(0));
        assertNoCallbacksLeft();
    }

    public function worksInsideFiber(): void
    {
        $nested = static function (Group $inner) {
            $inner->spawn(static fn() => delay(sec(1)));

            return 'nested';
        };

        $result = group(static fn(Group $outer) => $outer->spawn(static fn() => group($nested))->await());

        Assert::same($result, 'nested');
    }

    public function closesEveryScope(): void
    {
        $onlyChild = static function (Scope $scope) {
            $scopes = self::children($scope);
            Assert::count($scopes, 1);
            $child = current($scopes);
            \assert($child instanceof Scope);

            return $child;
        };
        $root = Scope::current();

        group(static function (Group $group) use ($onlyChild) {
            $body = Scope::current();
            timeout(sec(1000), static fn() => null);
            thrown(static fn() => timeout(sec(1), never(...)));
            shield(static fn() => null);
            $group->spawn(static fn() => null);
            $group->spawn(never(...))->cancel();
            $group->daemon(static fn() => null);
            $group->spawn(static function () {
                timeout(sec(1000), static fn() => delay(sec(1)));
                shield(static fn() => delay(sec(1)));
            })->await();
            delay(sec(1));

            $tasksScope = $onlyChild($body);
            $daemonsScope = $onlyChild($tasksScope);
            Assert::same(self::children($daemonsScope), []);
        });

        Assert::same(self::children($root), []);
    }

    public function scopesGoAwayWithoutCycleCollector(): void
    {
        $references = [];
        $gc = gc_enabled();
        gc_disable();

        try {
            group(static function (Group $group) use (&$references) {
                $parent = static function () {
                    $parent = (fn() => $this->parent)->call(Scope::current());
                    \assert($parent !== null);

                    return $parent;
                };
                $references['body'] = \WeakReference::create(Scope::current());
                $group->spawn(static function () use (&$references, $parent) {
                    $references['tasks'] = \WeakReference::create($parent());
                });
                $group->daemon(static function () use (&$references, $parent) {
                    $references['daemons'] = \WeakReference::create($parent());
                });
                delay(sec(1));
            });
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        Assert::count($references, 3);

        foreach ($references as $reference) {
            Assert::null($reference->get());
        }
    }

    /**
     * @return array<int, Scope>
     */
    private static function children(Scope $scope): array
    {
        return (fn() => $this->children)->call($scope);
    }
}
