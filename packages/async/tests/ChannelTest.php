<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;

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
        $this->assertHoldsNothing($channel);
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

    public function sendWithRoomInBufferDoesNotWait(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        timeout(0.001, static function () use ($channel) {
            try {
                delay(1);
            } catch (Cancelled) {
            }

            $channel->send('value');
        });

        Assert::same($channel->receive(), 'value');
    }

    public function receiveOfBufferedValueDoesNotWait(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('value');

        $value = timeout(0.001, static function () use ($channel) {
            try {
                delay(1);
            } catch (Cancelled) {
            }

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
            delay(0.001);

            $log[] = 'receiving';
            $log[] = $channel->receive();
            delay(0.001);
            $log[] = $channel->receive();
        });

        Assert::same($log, ['sent a', 'receiving', 'a', 'sent b', 'b']);
    }

    public function receiveWaitsForValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        EventLoop::delay(0.001, static function () use ($channel) {
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
            delay(0.001);

            $log[] = 'receiving';
            $log[] = $channel->receive();
        });

        Assert::same($log, ['receiving', 'value', 'sent']);
        $this->assertHoldsNothing($channel);
    }

    public function rendezvousSendToWaitingReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $value = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn($channel->receive(...));
            delay(0.001);

            $channel->send('value');

            return $receiver->await();
        });

        Assert::same($value, 'value');
    }

    public function trySendWithoutRoomReturnsFalse(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        Assert::true($channel->trySend('a'));
        Assert::false($channel->trySend('b'));
        Assert::same($channel->receive(), 'a');
    }

    public function trySendOnRendezvousWithoutReceiverReturnsFalse(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        Assert::false($channel->trySend('value'));
        $this->assertHoldsNothing($channel);
    }

    public function trySendHandsValueToWaitingReceiver(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $value = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn($channel->receive(...));
            delay(0.001);

            Assert::true($channel->trySend('value'));

            return $receiver->await();
        });

        Assert::same($value, 'value');
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
            delay(0.001);

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
            delay(0.001);

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
            delay(0.001);

            $log[] = $channel->receive();
            delay(0.001);
            $log[] = $channel->receive();
            delay(0.001);
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
            delay(0.001);

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
            delay(0.001);

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
            delay(0.001);

            $cancelled->cancel();

            Assert::false($channel->trySend('value'));
        });

        $this->assertHoldsNothing($channel);
    }

    public function receiverCancelledAfterHandoverKeepsValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);
        $received = null;

        group(static function (Group $group) use ($channel, &$received) {
            $receiver = $group->spawn(static function () use ($channel, &$received) {
                $received = $channel->receive();
                delay(1);
            });
            delay(0.001);

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
        EventLoop::delay(0.001, static function () use ($channel) {
            Assert::true($channel->trySend('value'));
        });

        $value = timeout(0.001, $channel->receive(...));

        Assert::same($value, 'value');
    }

    public function cancelledSenderDoesNotDeliverValue(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->send('first');

        $exception = thrown(static fn() => timeout(0.001, static function () use ($channel) {
            $channel->send('second');
        }));

        Assert::instanceOf($exception, TimedOut::class);
        $this->assertHoldsNothing($channel);
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
            delay(0.001);

            $cancelled->cancel(); // the cancelled sender is still in the queue: it has not run since

            Assert::same($channel->receive(), 'next');
            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);
        });

        $this->assertHoldsNothing($channel);
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
            delay(0.001);

            $cancelled->cancel();

            Assert::same($channel->receive(), 'first');
            Assert::same($channel->receive(), 'next');
        });

        $this->assertHoldsNothing($channel);
        Assert::false($this->isBuffered($channel));
    }

    public function receiveIsCancellable(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);

        $exception = thrown(static fn() => timeout(0.001, $channel->receive(...)));

        Assert::instanceOf($exception, TimedOut::class);
        $this->assertHoldsNothing($channel);
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
            delay(0.001);

            $channel->close();

            foreach ($receivers as $receiver) {
                Assert::instanceOf($receiver->await(), ChannelClosed::class);
            }
        });

        $this->assertHoldsNothing($channel);
    }

    public function closeEndsWaitingIterator(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(0);

        $received = group(static function (Group $group) use ($channel) {
            $receiver = $group->spawn(static fn() => iterator_to_array($channel));
            delay(0.001);

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
            delay(0.001);

            $channel->close();

            foreach ($senders as $sender) {
                Assert::instanceOf($sender->await(), ChannelClosed::class);
            }
        });

        $this->assertHoldsNothing($channel);
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

    public function trySendOnClosedChannelThrows(): void
    {
        /** @var Channel<string> */
        $channel = new Channel(1);
        $channel->close();

        Expect::exception(ChannelClosed::class);

        $channel->trySend('value');
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
        /** @var Channel<\stdClass> */
        $channel = new Channel(0);
        $references = [];

        group(static function (Group $group) use ($channel, &$references) {
            $receiver = $group->spawn($channel->receive(...));
            $group->spawn(static function () use ($channel, &$references) {
                $value = new \stdClass();
                $references[] = \WeakReference::create($value);

                try {
                    timeout(0.001, static fn() => $channel->send($value));
                } catch (TimedOut) {
                }
            });
            delay(0.002);

            $receiver->cancel();
        });

        $this->assertHoldsNothing($channel);
        $reference = array_pop($references);
        Assert::instanceOf($reference, \WeakReference::class);
        Assert::null($reference->get());
    }

    /**
     * @template T
     * @param Channel<T> $channel
     */
    private function assertHoldsNothing(Channel $channel): void
    {
        Assert::same((fn(): array => $this->receivers)->call($channel), []);
        Assert::same((fn(): array => $this->senders)->call($channel), []);
    }

    /**
     * @template T
     * @param Channel<T> $channel
     */
    private function isBuffered(Channel $channel): bool
    {
        return !(fn(): \SplQueue => $this->buffer)->call($channel)->isEmpty();
    }
}
