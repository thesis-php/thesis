<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\reaches;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

#[Test]
final class ChannelTest
{
    public function valuesAreReceivedInOrderOfSending(): void
    {
        /** @var Channel<int> */
        $channel = new Channel(2);

        $received = group(static function (Group $group) use ($channel) {
            $group->spawn(static function () use ($channel) {
                try {
                    for ($i = 1; $i <= 5; ++$i) {
                        $channel->send($i);
                    }
                } finally {
                    $channel->close();
                }
            });

            return iterator_to_array($channel, preserve_keys: false);
        });

        Assert::same($received, [1, 2, 3, 4, 5]);
        self::assertHoldsNothing($channel);
    }

    public function iteratorHasListKeys(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(2);
        $channel->send('a');
        $channel->send('b');
        $channel->close();

        Assert::same(iterator_to_array($channel), ['a', 'b']);
    }

    public function iterationBrokenOffTakesNothingMore(): void
    {
        /** @var Channel<int> */
        $channel = new Channel(3);
        $channel->send(1);
        $channel->send(2);
        $channel->send(3);
        $channel->close();
        $received = [];

        foreach ($channel as $value) {
            $received[] = $value;

            break;
        }

        Assert::same($received, [1]);

        foreach ($channel as $value) {
            $received[] = $value;
        }

        Assert::same($received, [1, 2, 3]);
    }

    public function iterationBrokenOffWhileWaitingCanBeResumed(): void
    {
        /** @var Channel<int> */
        $channel = new Channel(0);

        $received = group(static function (Group $group) use ($channel) {
            $group->spawn(static function () use ($channel) {
                foreach ([1, 2, 3] as $value) {
                    $channel->send($value);
                }
                $channel->close();
            });

            $iterator = $channel->getIterator();
            $received = [];

            foreach ($iterator as $value) {
                $received[] = $value;

                break;
            }

            $iterator->next(); // the same generator goes on waiting for the next value

            while ($iterator->valid()) {
                $received[] = $iterator->current();
                $iterator->next();
            }

            return $received;
        });

        Assert::same($received, [1, 2, 3]);
        self::assertHoldsNothing($channel);
    }

    public function sendWithRoomInBufferDoesNotWait(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        timeout(sec(1), static function () use ($channel) {
            untilCancelled();

            $channel->send('value');
        });

        Assert::same($channel->receive(), 'value');
    }

    public function receiveOfBufferedValueDoesNotWait(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('value');

        $value = timeout(sec(1), static function () use ($channel) {
            untilCancelled();

            return $channel->receive();
        });

        Assert::same($value, 'value');
    }

    public function sendWaitsForRoom(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $log = [];

        group(static function (Group $group) use ($channel, &$log) {
            $group->spawn(static function () use ($channel, &$log) {
                $channel->send('a');
                $log[] = 'sent a';
                $channel->send('b');
                $log[] = 'sent b';
            });
            delay(sec(1));

            $log[] = 'receiving';
            $log[] = $channel->receive();
            delay(sec(1));
            $log[] = $channel->receive();
        });

        Assert::same($log, ['sent a', 'receiving', 'a', 'sent b', 'b']);
    }

    public function receiveWaitsForValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        EventLoop::delay(1, static function () use ($channel) {
            $channel->trySend('value');
        });

        Assert::same($channel->receive(), 'value');
    }

    public function rendezvousSendWaitsForReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);
        $log = [];

        group(static function (Group $group) use ($channel, &$log) {
            $group->spawn(static function () use ($channel, &$log) {
                $channel->send('value');
                $log[] = 'sent';
            });
            delay(sec(1));

            $log[] = 'receiving';
            $log[] = $channel->receive();
        });

        Assert::same($log, ['receiving', 'value', 'sent']);
        self::assertHoldsNothing($channel);
    }

    public function rendezvousSendToWaitingReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $value = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn($channel->receive(...));
            delay(sec(1));

            $channel->send('value');

            return $receiver->await();
        });

        Assert::same($value, 'value');
    }

    public function countsValuesInBuffer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(2);
        $counts = [\count($channel)];
        $channel->send('a');
        $counts[] = \count($channel);
        $channel->send('b');
        $counts[] = \count($channel);
        $channel->receive();
        $counts[] = \count($channel);

        Assert::same($counts, [0, 1, 2, 1]);
        Assert::same($channel->capacity, 2);
    }

    public function doesNotCountValuesOfWaitingSenders(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $group->spawn(static fn() => $channel->send('value'));
            delay(sec(1));

            Assert::same(\count($channel), 0);
            Assert::same($channel->receive(), 'value');
        });
    }

    public function trySendWithoutRoomReturnsFull(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        Assert::same($channel->trySend('a'), SendResult::Sent);
        Assert::same($channel->trySend('b'), SendResult::Full);
        Assert::same($channel->receive(), 'a');
    }

    public function trySendOnRendezvousWithoutReceiverReturnsFull(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        Assert::same($channel->trySend('value'), SendResult::Full);
        self::assertHoldsNothing($channel);
    }

    public function trySendHandsValueToWaitingReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $value = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn($channel->receive(...));
            delay(sec(1));

            Assert::same($channel->trySend('value'), SendResult::Sent);

            return $receiver->await();
        });

        Assert::same($value, 'value');
    }

    public function tryReceiveReturnsBufferedValuesInOrder(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(2);
        $channel->send('a');
        $channel->send('b');

        Assert::same($channel->tryReceive(), 'a');
        Assert::same($channel->tryReceive(), 'b');
    }

    public function tryReceiveOfEmptyChannelReturnsNull(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        Assert::null($channel->tryReceive());
        self::assertHoldsNothing($channel);
    }

    public function tryReceiveOfEmptyChannelReturnsDefault(): void
    {
        /** @var Channel<?string> */
        $channel = new Channel(1);
        $none = new \stdClass();
        $channel->send(null);

        Assert::null($channel->tryReceive($none));
        Assert::same($channel->tryReceive($none), $none);
    }

    public function tryReceiveDoesNotWaitInCancelledScope(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        $value = timeout(sec(1), static function () use ($channel) {
            untilCancelled();

            return $channel->tryReceive('nothing');
        });

        Assert::same($value, 'nothing');
    }

    public function tryReceiveTakesValueOfWaitingRendezvousSender(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $sender = $group->spawn(static fn() => $channel->send('value'));
            delay(sec(1));

            Assert::same($channel->tryReceive(), 'value');
            $sender->await();
        });

        self::assertHoldsNothing($channel);
    }

    public function tryReceiveSkipsCancelledRendezvousSender(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $cancelled = $group->spawn(static fn() => $channel->send('cancelled'));
            delay(sec(1));

            $cancelled->cancel();

            Assert::null($channel->tryReceive());
        });

        self::assertHoldsNothing($channel);
    }

    public function tryReceiveLetsWaitingSenderIntoBuffer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        group(static function (Group $group) use ($channel) {
            $channel->send('first');
            $sender = $group->spawn(static fn() => $channel->send('second'));
            delay(sec(1));

            Assert::same($channel->tryReceive(), 'first');
            $sender->await();
            Assert::same($channel->tryReceive(), 'second');
        });
    }

    public function tryReceiveDrainsBufferAfterCloseThenReturnsDefault(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('value');
        $channel->close();

        Assert::same($channel->tryReceive(), 'value');
        Assert::null($channel->tryReceive());
        Assert::same($channel->tryReceive('nothing'), 'nothing');
        Assert::instanceOf(thrown($channel->receive(...)), ChannelClosed::class);
    }

    public function readmeBatchingKeepsLastBatchOfClosedChannel(): void
    {
        /** @var Channel<int> */
        $events = new Channel(250);
        $batches = [];
        $store = static function (array $batch) use (&$batches) {
            $batches[] = \count($batch);
        };

        foreach (range(1, 230) as $value) {
            $events->send($value);
        }
        $events->close();

        // Verbatim from README.md, with store() as a closure.
        foreach ($events as $event) {
            $batch = [$event];

            while (\count($batch) < 100 && ($next = $events->tryReceive()) !== null) {
                $batch[] = $next;
            }

            $store($batch);
        }

        Assert::same($batches, [100, 100, 30]);
    }

    public function batchingStoresLastBatchBeforeReasonOfClose(): void
    {
        /** @var Channel<int> */
        $events = new Channel(10);
        $reason = new \RuntimeException('connection lost');
        $batches = [];

        foreach (range(1, 5) as $value) {
            $events->send($value);
        }
        $events->close($reason);

        $exception = thrown(static function () use ($events, &$batches) {
            foreach ($events as $event) {
                $batch = [$event];

                while (\count($batch) < 100 && ($next = $events->tryReceive()) !== null) {
                    $batch[] = $next;
                }

                $batches[] = $batch;
            }
        });

        Assert::same($batches, [[1, 2, 3, 4, 5]]);
        Assert::same($exception, $reason);
    }

    public function receiversGetValuesInOrderOfArrival(): void
    {
        /** @var Channel<int> */
        $channel = new Channel(0);
        $log = [];

        group(static function (Group $group) use ($channel, &$log) {
            foreach (['a', 'b', 'c'] as $name) {
                $group->spawn(static function () use ($channel, $name, &$log) {
                    $log[] = $name . $channel->receive();
                });
            }
            delay(sec(1));

            for ($i = 1; $i <= 3; ++$i) {
                $channel->send($i);
            }
        });

        Assert::same($log, ['a1', 'b2', 'c3']);
    }

    public function sendersAreTakenInOrderOfArrival(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        $received = group(static function (Group $group) use ($channel) {
            $channel->send('first');

            foreach (['a', 'b', 'c'] as $name) {
                $group->spawn(static function () use ($channel, $name) {
                    $channel->send($name);
                });
            }
            delay(sec(1));

            return [$channel->receive(), $channel->receive(), $channel->receive(), $channel->receive()];
        });

        Assert::same($received, ['first', 'a', 'b', 'c']);
    }

    public function receiveFromFullBufferLetsInOneWaitingSender(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $log = [];

        group(static function (Group $group) use ($channel, &$log) {
            $channel->send('first');

            foreach (['a', 'b'] as $name) {
                $group->spawn(static function () use ($channel, $name, &$log) {
                    $channel->send($name);
                    $log[] = 'sent ' . $name;
                });
            }
            delay(sec(1));

            $log[] = $channel->receive();
            delay(sec(1));
            $log[] = $channel->receive();
            delay(sec(1));
            $log[] = $channel->receive();
        });

        Assert::same($log, ['first', 'sent a', 'a', 'sent b', 'b']);
    }

    public function valueOfCancelledReceiverGoesToNextReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $cancelled = $group->spawn($channel->receive(...));
            $next = $group->spawn($channel->receive(...));
            delay(sec(1));

            $cancelled->cancel();
            $channel->send('value'); // the cancelled receiver is still in the queue: it has not run since

            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);
            Assert::same($next->await(), 'value');
        });
    }

    public function valueOfCancelledReceiverGoesToBuffer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        group(static function (Group $group) use ($channel) {
            $cancelled = $group->spawn($channel->receive(...));
            delay(sec(1));

            $cancelled->cancel();
            $channel->send('value');

            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);
        });

        Assert::same($channel->receive(), 'value');
    }

    public function trySendSkipsCancelledReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $cancelled = $group->spawn($channel->receive(...));
            delay(sec(1));

            $cancelled->cancel();

            Assert::same($channel->trySend('value'), SendResult::Full);
        });

        self::assertHoldsNothing($channel);
    }

    public function receiverCancelledAfterHandoverKeepsValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);
        $received = null;

        group(static function (Group $group) use ($channel, &$received) {
            $receiver = $group->spawn(static function () use ($channel, &$received) {
                $received = $channel->receive();
                never();
            });
            delay(sec(1));

            $channel->send('value');
            $receiver->cancel();

            thrown($receiver->await(...));
        });

        Assert::same($received, 'value');
    }

    public function timeoutAroundReceiveDoesNotLoseValueSentWithTimer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        // Both callbacks run in the same tick, the send first: the value wins over the deadline.
        EventLoop::delay(1, static function () use ($channel) {
            Assert::same($channel->trySend('value'), SendResult::Sent);
        });

        $value = timeout(sec(1), $channel->receive(...));

        Assert::same($value, 'value');
    }

    public function cancelledSenderDoesNotDeliverValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('first');

        $exception = thrown(static fn() => timeout(sec(1), static function () use ($channel) {
            $channel->send('second');
        }));

        Assert::instanceOf($exception, TimedOut::class);
        self::assertHoldsNothing($channel);
        assertNoCallbacksLeft();
        Assert::same($channel->receive(), 'first');
        Assert::false($this->isBuffered($channel));
    }

    public function cancelledRendezvousSenderDoesNotDeliverValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            $cancelled = $group->spawn(static function () use ($channel) {
                $channel->send('cancelled');
            });
            $group->spawn(static function () use ($channel) {
                $channel->send('next');
            });
            delay(sec(1));

            $cancelled->cancel(); // the cancelled sender is still in the queue: it has not run since

            Assert::same($channel->receive(), 'next');
            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);
        });

        self::assertHoldsNothing($channel);
    }

    public function cancelledSenderIsSkippedWhenBufferFreesUp(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        group(static function (Group $group) use ($channel) {
            $channel->send('first');
            $cancelled = $group->spawn(static function () use ($channel) {
                $channel->send('cancelled');
            });
            $group->spawn(static function () use ($channel) {
                $channel->send('next');
            });
            delay(sec(1));

            $cancelled->cancel();

            Assert::same($channel->receive(), 'first');
            Assert::same($channel->receive(), 'next');
        });

        self::assertHoldsNothing($channel);
        Assert::false($this->isBuffered($channel));
    }

    public function receiveIsCancellable(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        $exception = thrown(static fn() => timeout(sec(1), $channel->receive(...)));

        Assert::instanceOf($exception, TimedOut::class);
        self::assertHoldsNothing($channel);
        assertNoCallbacksLeft();
    }

    public function receiversDrainBufferAfterClose(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(2);
        $channel->send('a');
        $channel->send('b');
        $channel->close();

        Assert::same($channel->receive(), 'a');
        Assert::same($channel->receive(), 'b');
        Assert::instanceOf(thrown($channel->receive(...)), ChannelClosed::class);
    }

    public function closeWakesWaitingReceivers(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        group(static function (Group $group) use ($channel) {
            // Caught inside: a task that failed with ChannelClosed would fail the group and cancel the other one.
            $receivers = [
                $group->spawn(static fn() => thrown($channel->receive(...))),
                $group->spawn(static fn() => thrown($channel->receive(...))),
            ];
            delay(sec(1));

            $channel->close();

            foreach ($receivers as $receiver) {
                Assert::instanceOf($receiver->await(), ChannelClosed::class);
            }
        });

        self::assertHoldsNothing($channel);
    }

    public function closeEndsWaitingIterator(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $received = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn(static fn() => iterator_to_array($channel));
            delay(sec(1));

            $channel->send('value');
            $channel->close();

            return $receiver->await();
        });

        Assert::same($received, ['value']);
    }

    public function closeWakesWaitingSendersWithoutDeliveringTheirValues(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('buffered');

        group(static function (Group $group) use ($channel) {
            $senders = [
                $group->spawn(static fn() => thrown(static fn() => $channel->send('a'))),
                $group->spawn(static fn() => thrown(static fn() => $channel->send('b'))),
            ];
            delay(sec(1));

            $channel->close();

            foreach ($senders as $sender) {
                Assert::instanceOf($sender->await(), ChannelClosed::class);
            }
        });

        self::assertHoldsNothing($channel);
        Assert::same(iterator_to_array($channel), ['buffered']);
    }

    public function sendOnClosedChannelThrows(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->close();

        Expect::exception(ChannelClosed::class)->withMessage('The channel is closed.');

        $channel->send('value');
    }

    public function trySendOnClosedChannelReturnsClosed(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->close();

        Assert::same($channel->trySend('value'), SendResult::Closed);
        self::assertHoldsNothing($channel);
    }

    public function closeIsIdempotent(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('value');
        $channel->close();
        $channel->close();

        Assert::same(iterator_to_array($channel), ['value']);
    }

    public function isClosedOnlyAfterClose(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        Assert::false($channel->isClosed);

        $channel->close();

        Assert::true($channel->isClosed);
    }

    public function receiversGetReasonOfCloseAfterDrainingBuffer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $reason = new \RuntimeException('connection lost');
        $channel->send('value');
        $channel->close($reason);

        Assert::same($channel->receive(), 'value');
        Assert::null($channel->tryReceive());
        Assert::same(thrown($channel->receive(...)), $reason);
    }

    public function closeWithReasonWakesWaitingReceiversWithIt(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);
        $reason = new \RuntimeException('connection lost');

        group(static function (Group $group) use ($channel, $reason) {
            $receiver = $group->spawn(static fn() => thrown($channel->receive(...)));
            delay(sec(1));

            $channel->close($reason);

            Assert::same($receiver->await(), $reason);
        });
    }

    public function iteratorThrowsReasonOfCloseAfterDrainingBuffer(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(2);
        $reason = new \RuntimeException('connection lost');
        $channel->send('a');
        $channel->send('b');
        $channel->close($reason);
        $received = [];

        $exception = thrown(static function () use ($channel, &$received) {
            foreach ($channel as $value) {
                $received[] = $value;
            }
        });

        Assert::same($received, ['a', 'b']);
        Assert::same($exception, $reason);
    }

    public function sendersGetReasonOfCloseAsPrevious(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);
        $reason = new \RuntimeException('connection lost');

        group(static function (Group $group) use ($channel, $reason) {
            $sender = $group->spawn(static fn() => thrown(static fn() => $channel->send('waiting')));
            delay(sec(1));

            $channel->close($reason);

            $waiting = $sender->await();
            Assert::instanceOf($waiting, ChannelClosed::class);
            Assert::same($waiting->getPrevious(), $reason);
        });

        $late = thrown(static fn() => $channel->send('late'));
        Assert::instanceOf($late, ChannelClosed::class);
        Assert::same($late->getPrevious(), $reason);
    }

    public function closeWithoutReasonGivesChannelClosedWithoutPrevious(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->close();

        Assert::null(thrown(static fn() => $channel->send('late'))->getPrevious());
    }

    public function laterCloseDoesNotReplaceReason(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $first = new \RuntimeException('first');
        $channel->close($first);
        $channel->close(new \RuntimeException('second'));

        Assert::same(thrown($channel->receive(...)), $first);
    }

    public function laterCloseWithReasonDoesNotTurnEndIntoFailure(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->close();
        $channel->close(new \RuntimeException('too late'));

        Assert::instanceOf(thrown($channel->receive(...)), ChannelClosed::class);
        Assert::same(iterator_to_array($channel), []);
    }

    public function closeRejectsCancelled(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        $exception = thrown(static fn() => $channel->close(Cancelled::create()));

        Assert::instanceOf($exception, \ValueError::class);
        Assert::false($channel->isClosed);
    }

    public function nullIsValue(): void
    {
        /** @var Channel<null> */
        $channel = new Channel(1);
        $channel->send(null);
        $channel->close();

        Assert::same(iterator_to_array($channel), [null]);
    }

    public function channelHoldsNoWaitersAfterWaitsAreOver(): void
    {
        /** @var Channel<string> */
        $toReceive = new Channel(0);
        /** @var Channel<\stdClass> */
        $toSend = new Channel(0);
        $values = [new \stdClass(), new \stdClass()];

        group(static function (Group $group) use ($toReceive, $toSend, $values) {
            $handles = [
                $group->spawn($toReceive->receive(...)),
                $group->spawn($toReceive->receive(...)),
            ];

            foreach ($values as $value) {
                $handles[] = $group->spawn(static fn() => $toSend->send($value));
            }
            delay(sec(1));

            Assert::count(self::waiters($toReceive)['receivers'], 2);
            Assert::count(self::waiters($toSend)['senders'], 2);
            Assert::true(reaches($toSend, $values[0]));

            foreach ($handles as $handle) {
                $handle->cancel();
            }
        });

        self::assertHoldsNothing($toReceive);
        self::assertHoldsNothing($toSend);

        foreach ($values as $value) {
            Assert::false(reaches($toSend, $value));
        }
    }

    /**
     * @template T
     * @param Channel<T> $channel
     */
    private static function assertHoldsNothing(Channel $channel): void
    {
        Assert::same(self::waiters($channel), ['receivers' => [], 'senders' => []]);
    }

    /**
     * @template T
     * @param Channel<T> $channel
     * @return array{receivers: array<mixed>, senders: array<mixed>}
     */
    private static function waiters(Channel $channel): array
    {
        return (fn() => [
            'receivers' => $this->receivers->items,
            'senders' => $this->senders->items,
        ])->call($channel);
    }

    /**
     * @template T
     * @param Channel<T> $channel
     */
    private function isBuffered(Channel $channel): bool
    {
        return !(fn() => $this->buffer)->call($channel)->isEmpty();
    }
}
