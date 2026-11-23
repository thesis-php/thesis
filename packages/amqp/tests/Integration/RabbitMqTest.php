<?php

declare(strict_types=1);

namespace Thesis\Amqp\Integration;

use Testo\Assert;
use Testo\Test;
use Thesis\Amqp\ChannelClosed;
use Thesis\Amqp\Client;
use Thesis\Amqp\DeliveryMode;
use Thesis\Amqp\Message;
use Thesis\Amqp\MessageReturned;
use Thesis\Amqp\Publish;
use Thesis\Amqp\ReplyCode;
use function Thesis\Amqp\thrown;
use function Thesis\Amqp\withRabbitMq;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class RabbitMqTest
{
    public function deliversWhatIsPublished(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $channel->confirmSelect();
            $queue = $channel->queueDeclare(exclusive: true);
            $message = new Message(
                body: str_repeat('x', 300_000),
                headers: ['attempt' => 2, 'tags' => ['a', 'b'], 'nested' => ['ok' => true]],
                contentType: 'text/plain',
                deliveryMode: DeliveryMode::Persistent,
                priority: 3,
                correlationId: 'c-1',
                replyTo: 'replies',
                expiration: sec(60),
                messageId: 'm-1',
                timestamp: new \DateTimeImmutable('@1700000000'),
                type: 'test',
                appId: 'thesis',
            );

            $channel->publish($message, routingKey: $queue->name);

            foreach ($channel->consume($queue->name) as $delivery) {
                Assert::equals($delivery->message, $message);
                Assert::same($delivery->routingKey, $queue->name);
                $delivery->ack();
                break;
            }
        });
    }

    public function returnsAMandatoryMessageThatNoQueueTakes(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $channel->confirmSelect();

            $nowhere = 'no-such-queue-' . bin2hex(random_bytes(4));
            $publish = static fn() => $channel->publish(new Message('lost'), routingKey: $nowhere, mandatory: true);

            $returned = thrown($publish);

            Assert::instanceOf($returned, MessageReturned::class);
            Assert::same($returned->replyCode, ReplyCode::NoRoute);
            Assert::equals($returned->returnedMessage, new Message('lost'));
        });
    }

    public function confirmsABatch(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $channel->confirmSelect();
            $queue = $channel->queueDeclare(exclusive: true);

            $channel->publishBatch((static function () use ($queue) {
                for ($i = 0; $i < 20_000; ++$i) {
                    yield new Publish(new Message("m{$i}"), routingKey: $queue->name);
                }
            })());

            Assert::same($channel->queueDeclare($queue->name, passive: true)->messages, 20_000);
        });
    }

    public function takesBatchesOfDeliveries(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $channel->confirmSelect();
            $queue = $channel->queueDeclare(exclusive: true);

            for ($i = 0; $i < 10; ++$i) {
                $channel->publish(new Message("m{$i}"), routingKey: $queue->name);
            }

            $consumer = $channel->consume($queue->name);
            $sizes = [];

            foreach ($consumer->batches(count: 4, timeout: ms(200)) as $batch) {
                $sizes[] = \count($batch);
                $batch->ack();

                if (array_sum($sizes) === 10) {
                    $consumer->cancel();
                }
            }

            Assert::same($sizes, [4, 4, 2]);
            Assert::same($channel->queueDeclare($queue->name, passive: true)->messages, 0);
        });
    }

    public function closesTheChannelOfAFailedMethod(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();

            $missing = 'no-such-queue-' . bin2hex(random_bytes(4));

            $failure = thrown(static fn() => $channel->queueDeclare($missing, passive: true));

            Assert::instanceOf($failure, ChannelClosed::class);
            Assert::same($failure->replyCode, ReplyCode::NotFound);
            Assert::true($channel->isClosed);
            Assert::same($client->channel()->queueDeclare(exclusive: true)->messages, 0);
        });
    }

    public function getsOneMessage(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $channel->confirmSelect();
            $queue = $channel->queueDeclare(exclusive: true);

            Assert::null($channel->get($queue->name));

            $channel->publish(new Message('one'), routingKey: $queue->name);
            $delivery = $channel->get($queue->name, noAck: true);

            Assert::same($delivery?->message->body, 'one');
            Assert::null($channel->get($queue->name));
        });
    }

    public function rollsBackATransactionThatThrows(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $queue = $channel->queueDeclare(exclusive: true);

            try {
                $channel->transactional(static function () use ($channel, $queue) {
                    $channel->publish(new Message('rolled back'), routingKey: $queue->name);

                    throw new \RuntimeException('Abort.');
                });
            } catch (\RuntimeException) {
            }

            $commit = static fn() => $channel->publish(new Message('committed'), routingKey: $queue->name);
            $channel->transactional($commit);

            Assert::same($channel->get($queue->name, noAck: true)?->message->body, 'committed');
            Assert::null($channel->get($queue->name));
        });
    }

    public function cancellingAnOldConsumerLeavesItsReplacementRunning(): void
    {
        withRabbitMq(static function (Client $client) {
            $channel = $client->channel();
            $queue = $channel->queueDeclare(exclusive: true)->name;
            $old = $channel->consume($queue, 'same');
            $old->cancel();
            $channel->consume($queue, 'same');
            $old->cancel();
            Assert::same($channel->queueDeclare($queue, passive: true)->consumers, 1);
        });
    }
}
