<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Amqp\Internal\Confirms;
use Thesis\Amqp\Internal\Connection;
use Thesis\Amqp\Internal\ConsumerState;
use Thesis\Amqp\Internal\Content;
use Thesis\Amqp\Internal\ContentHeader;
use Thesis\Amqp\Internal\Frames;
use Thesis\Amqp\Internal\PendingCall;
use Thesis\Amqp\Internal\Protocol\BasicAck;
use Thesis\Amqp\Internal\Protocol\BasicCancel;
use Thesis\Amqp\Internal\Protocol\BasicCancelOk;
use Thesis\Amqp\Internal\Protocol\BasicConsume;
use Thesis\Amqp\Internal\Protocol\BasicConsumeOk;
use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGet;
use Thesis\Amqp\Internal\Protocol\BasicGetEmpty;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\BasicNack;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\BasicQos;
use Thesis\Amqp\Internal\Protocol\BasicQosOk;
use Thesis\Amqp\Internal\Protocol\BasicRecover;
use Thesis\Amqp\Internal\Protocol\BasicRecoverOk;
use Thesis\Amqp\Internal\Protocol\BasicReject;
use Thesis\Amqp\Internal\Protocol\BasicReturn;
use Thesis\Amqp\Internal\Protocol\ChannelClose;
use Thesis\Amqp\Internal\Protocol\ChannelCloseOk;
use Thesis\Amqp\Internal\Protocol\ChannelFlow;
use Thesis\Amqp\Internal\Protocol\ChannelFlowOk;
use Thesis\Amqp\Internal\Protocol\ChannelOpen;
use Thesis\Amqp\Internal\Protocol\ChannelOpenOk;
use Thesis\Amqp\Internal\Protocol\ConfirmSelect;
use Thesis\Amqp\Internal\Protocol\ConfirmSelectOk;
use Thesis\Amqp\Internal\Protocol\ExchangeBind;
use Thesis\Amqp\Internal\Protocol\ExchangeBindOk;
use Thesis\Amqp\Internal\Protocol\ExchangeDeclare;
use Thesis\Amqp\Internal\Protocol\ExchangeDeclareOk;
use Thesis\Amqp\Internal\Protocol\ExchangeDelete;
use Thesis\Amqp\Internal\Protocol\ExchangeDeleteOk;
use Thesis\Amqp\Internal\Protocol\ExchangeUnbind;
use Thesis\Amqp\Internal\Protocol\ExchangeUnbindOk;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\Internal\Protocol\QueueBind;
use Thesis\Amqp\Internal\Protocol\QueueBindOk;
use Thesis\Amqp\Internal\Protocol\QueueDeclare;
use Thesis\Amqp\Internal\Protocol\QueueDeclareOk;
use Thesis\Amqp\Internal\Protocol\QueueDelete;
use Thesis\Amqp\Internal\Protocol\QueueDeleteOk;
use Thesis\Amqp\Internal\Protocol\QueuePurge;
use Thesis\Amqp\Internal\Protocol\QueuePurgeOk;
use Thesis\Amqp\Internal\Protocol\QueueUnbind;
use Thesis\Amqp\Internal\Protocol\QueueUnbindOk;
use Thesis\Amqp\Internal\Protocol\TxCommit;
use Thesis\Amqp\Internal\Protocol\TxCommitOk;
use Thesis\Amqp\Internal\Protocol\TxRollback;
use Thesis\Amqp\Internal\Protocol\TxRollbackOk;
use Thesis\Amqp\Internal\Protocol\TxSelect;
use Thesis\Amqp\Internal\Protocol\TxSelectOk;
use Thesis\Async\Channel as AsyncChannel;
use Thesis\Async\Deferred;
use Thesis\Async\LocalMutex;
use Thesis\Async\Lock;
use Thesis\Async\ScopedValue;
use Thesis\Io\Buffer;
use function Thesis\Amqp\Internal\properties;
use function Thesis\Async\shield;

/**
 * A channel of a connection, opened by {@see Client::channel()}. Any number of fibers may use it at once:
 * synchronous methods (declarations, qos, get, ...) go one after another, as the protocol requires,
 * publishes and acknowledgements go out as they come.
 *
 * Once closed, by {@see close()}, by the server after a failed method, or with its connection, every method throws
 * the reason: {@see ChannelClosed} or {@see ConnectionClosed}. A channel is never reopened: open a new one.
 *
 * @api
 */
final class Channel
{
    /**
     * How many bytes of a batch are written at once.
     */
    private const int BATCH_BYTES = 1024 * 1024;

    /**
     * How many messages of a batch may wait for a confirm before the batch waits for the oldest one.
     */
    private const int BATCH_WINDOW = 10_000;

    public private(set) bool $isClosed = false;

    private ?AmqpException $closed = null;

    private readonly LocalMutex $callMutex;

    private ?PendingCall $pending = null;

    private readonly Content $content;

    /**
     * By consumer tag: a numeric one is an int key.
     *
     * @var array<array-key, ConsumerState>
     */
    private array $consumers = [];

    private int $consumerCount = 0;

    /**
     * The highest delivery tag settled with `multiple`: every tag up to it is settled, so a later ack of one of them
     * is dropped, otherwise the server would close the channel for an unknown delivery tag.
     */
    private int $settledUpTo = 0;

    private null|ConfirmSelect|TxSelect $mode = null;

    private readonly LocalMutex $writeMutex;

    /**
     * @var ?Deferred<null> completed after the transaction commits or rolls back
     */
    private ?Deferred $activeTransaction = null;

    /**
     * @var ScopedValue<?Deferred<null>> inherited by tasks started inside the transaction callback
     */
    private readonly ScopedValue $transaction;

    private readonly Confirms $confirms;

    /**
     * @internal
     * @param int<1, 65535> $id
     * @throws ConnectionClosed
     */
    public function __construct(
        private readonly Connection $connection,
        public readonly int $id,
    ) {
        $this->callMutex = new LocalMutex();
        $this->writeMutex = new LocalMutex();
        /** @var ScopedValue<?Deferred<null>> $transaction */
        $transaction = new ScopedValue(null);
        $this->transaction = $transaction;
        $this->confirms = new Confirms();
        $this->content = new Content($id);
        $connection->register($id, $this->handle(...), $this->terminate(...), $this->drain(...));

        try {
            // Shielded: a channel that the server has opened after the caller gave up would hold its id,
            // and opening it again is a connection error.
            shield(fn() => $this->call(new ChannelOpen(), ChannelOpenOk::class));
        } catch (\Throwable $exception) {
            if (!$this->isClosed) {
                $connection->unregister($id);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws AmqpException
     */
    public function exchangeDeclare(
        string $exchange,
        string $type = 'direct',
        bool $passive = false,
        bool $durable = false,
        bool $autoDelete = false,
        bool $internal = false,
        array $arguments = [],
    ): void {
        $this->call(
            new ExchangeDeclare(
                exchange: $exchange,
                type: $type,
                passive: $passive,
                durable: $durable,
                autoDelete: $autoDelete,
                internal: $internal,
                noWait: false,
                arguments: $arguments,
            ),
            ExchangeDeclareOk::class,
        );
    }

    /**
     * @throws AmqpException
     */
    public function exchangeDelete(string $exchange, bool $ifUnused = false): void
    {
        $this->call(new ExchangeDelete($exchange, $ifUnused, noWait: false), ExchangeDeleteOk::class);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws AmqpException
     */
    public function exchangeBind(
        string $destination,
        string $source,
        string $routingKey = '',
        array $arguments = [],
    ): void {
        $this->call(
            new ExchangeBind($destination, $source, $routingKey, noWait: false, arguments: $arguments),
            ExchangeBindOk::class,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws AmqpException
     */
    public function exchangeUnbind(
        string $destination,
        string $source,
        string $routingKey = '',
        array $arguments = [],
    ): void {
        $this->call(
            new ExchangeUnbind($destination, $source, $routingKey, noWait: false, arguments: $arguments),
            ExchangeUnbindOk::class,
        );
    }

    /**
     * @param string $queue empty for a name generated by the server, returned in {@see Queue::$name}
     * @param array<string, mixed> $arguments `x-queue-type`, `x-message-ttl`, `x-dead-letter-exchange`, ...
     * @throws AmqpException
     */
    public function queueDeclare(
        string $queue = '',
        bool $passive = false,
        bool $durable = false,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = [],
    ): Queue {
        $declared = $this->call(
            new QueueDeclare(
                queue: $queue,
                passive: $passive,
                durable: $durable,
                exclusive: $exclusive,
                autoDelete: $autoDelete,
                noWait: false,
                arguments: $arguments,
            ),
            QueueDeclareOk::class,
        );
        \assert($declared instanceof QueueDeclareOk && $declared->queue !== '');

        return new Queue($declared->queue, $declared->messageCount, $declared->consumerCount);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws AmqpException
     */
    public function queueBind(string $queue, string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->call(
            new QueueBind($queue, $exchange, $routingKey, noWait: false, arguments: $arguments),
            QueueBindOk::class,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws AmqpException
     */
    public function queueUnbind(string $queue, string $exchange, string $routingKey = '', array $arguments = []): void
    {
        $this->call(new QueueUnbind($queue, $exchange, $routingKey, $arguments), QueueUnbindOk::class);
    }

    /**
     * @return non-negative-int how many messages were purged
     * @throws AmqpException
     */
    public function queuePurge(string $queue): int
    {
        $purged = $this->call(new QueuePurge($queue, noWait: false), QueuePurgeOk::class);
        \assert($purged instanceof QueuePurgeOk);

        return $purged->messageCount;
    }

    /**
     * @return non-negative-int how many messages were deleted with the queue
     * @throws AmqpException
     */
    public function queueDelete(string $queue, bool $ifUnused = false, bool $ifEmpty = false): int
    {
        $deleted = $this->call(
            new QueueDelete($queue, $ifUnused, $ifEmpty, noWait: false),
            QueueDeleteOk::class,
        );
        \assert($deleted instanceof QueueDeleteOk);

        return $deleted->messageCount;
    }

    /**
     * Limits the deliveries that are not acknowledged yet. RabbitMQ applies it to the consumers started afterwards,
     * each on its own (`global: true` to all of them together), and ignores `$prefetchSize`.
     *
     * @param int<0, 65535> $prefetchCount 0 for no limit
     * @param int<0, 4294967295> $prefetchSize
     * @throws AmqpException
     */
    public function qos(int $prefetchCount = 0, int $prefetchSize = 0, bool $global = false): void
    {
        $this->call(new BasicQos($prefetchSize, $prefetchCount, $global), BasicQosOk::class);
    }

    /**
     * Starts a consumer. Its deliveries are buffered until they are taken: with `noAck: false` the server
     * sends at most the prefetch of {@see qos()} ahead, with `noAck: true` nothing limits it.
     * The consumer ends with {@see Consumer::cancel()}, {@see close()} or {@see Client::close()}.
     *
     * @param string $consumerTag generated if empty
     * @param array<string, mixed> $arguments `x-priority`, `x-stream-offset`, ...
     * @throws AmqpException
     * @throws \LogicException if a consumer with the tag already exists on the channel
     */
    public function consume(
        string $queue,
        string $consumerTag = '',
        bool $noAck = false,
        bool $exclusive = false,
        bool $noLocal = false,
        array $arguments = [],
    ): Consumer {
        $this->ensureOpen();

        // The tag is known before consume-ok, so that a delivery right after it finds its consumer.
        $tag = $consumerTag === '' ? "ctag-{$this->id}." . ++$this->consumerCount : $consumerTag;

        if (isset($this->consumers[$tag])) {
            throw new \LogicException("A consumer with tag \"{$tag}\" already exists on channel {$this->id}.");
        }

        /** @var AsyncChannel<Delivery> $deliveries */
        $deliveries = new AsyncChannel(PHP_INT_MAX);
        $consumer = $this->consumers[$tag] = new ConsumerState($deliveries, $noAck);

        try {
            // Shielded: a consumer started for a caller that is gone would take messages nobody reads.
            shield(fn() => $this->call(
                new BasicConsume(
                    queue: $queue,
                    consumerTag: $tag,
                    noLocal: $noLocal,
                    noAck: $noAck,
                    exclusive: $exclusive,
                    noWait: false,
                    arguments: $arguments,
                ),
                BasicConsumeOk::class,
            ));
        } catch (\Throwable $exception) {
            // @phpstan-ignore nullCoalesce.initializedProperty (the reader may have removed the tag during the RPC)
            if (($this->consumers[$tag] ?? null) === $consumer) {
                unset($this->consumers[$tag]);
            }

            throw $exception;
        }

        return new Consumer($tag, $deliveries, fn() => $this->cancelConsumer($tag, $consumer));
    }

    /**
     * Takes one message from the queue, null if it is empty.
     *
     * @throws AmqpException
     */
    public function get(string $queue, bool $noAck = false): ?Delivery
    {
        // Shielded: a delivery that nobody receives would stay unacknowledged until the channel closes.
        $delivery = shield(fn() => $this->call(new BasicGet($queue, $noAck), BasicGetOk::class, BasicGetEmpty::class));
        \assert($delivery === null || $delivery instanceof Delivery);

        return $delivery;
    }

    /**
     * Redelivers the messages that are delivered on this channel and not acknowledged yet.
     *
     * @throws AmqpException
     */
    public function recover(bool $requeue = true): void
    {
        $this->call(new BasicRecover($requeue), BasicRecoverOk::class);
    }

    /**
     * Turns publisher confirms on: from now on {@see publish()} returns once the broker has taken the message,
     * and throws {@see PublishNacked} or, for a mandatory message routed nowhere, {@see MessageReturned}.
     * Concurrent publishes are confirmed together, so the throughput of a channel does not suffer.
     *
     * @throws AmqpException
     * @throws \LogicException if the channel is transactional
     */
    public function confirmSelect(): void
    {
        shield(fn() => $this->selectMode(new ConfirmSelect(nowait: false)));
    }

    /**
     * Without confirms, returns once the message is written to the socket: the broker may still lose it.
     * With confirms ({@see ConfirmSelect()}), returns once the broker has taken it. If it throws {@see Cancelled}
     * while waiting for the confirm, the message has been published, only its fate is unknown.
     *
     * Waits while the server blocks the connection for lack of memory or disk (connection.blocked).
     *
     * @param bool $mandatory true to ask RabbitMQ to return the message if no queue accepts it. Requires confirms
     *     so the publisher can throw {@see MessageReturned}. The message carries the `x-thesis-return-id` header.
     * @throws AmqpException
     * @throws PublishNacked
     * @throws MessageReturned
     * @throws \LogicException if $mandatory is true and confirms are disabled
     * @throws \ValueError if the exchange, the routing key or a property does not fit the protocol
     */
    public function publish(
        Message $message,
        string $exchange = '',
        string $routingKey = '',
        bool $mandatory = false,
    ): void {
        $this->ensureOpen();
        $publish = new Publish($message, $exchange, $routingKey, $mandatory);
        $buffer = new Buffer();
        $returnId = $this->encodePublish($buffer, $publish);
        // Without confirms there is none.
        $confirm = $this->writePublishes($buffer->drain(), [[$publish, $returnId]])[0][1] ?? null;
        $confirm?->await();
    }

    /**
     * Publishes a batch with few writes. In confirm mode, it waits for every message to settle.
     * {@see PublishBatchFailed} lists rejected or returned messages. RabbitMQ confirmed the rest.
     * At most 10 000 messages wait for their confirm at once.
     *
     * @param iterable<Publish> $publishes
     * @throws AmqpException
     * @throws PublishBatchFailed
     * @throws \LogicException if a message is mandatory without confirms
     * @throws \ValueError if a message does not fit the protocol
     */
    public function publishBatch(iterable $publishes): void
    {
        $this->ensureOpen();

        /** @var \SplQueue<array{Publish, Deferred<null>}> $pending */
        $pending = new \SplQueue();
        /** @var list<Publish> $nacked */
        $nacked = [];
        /** @var list<Publish> $returned */
        $returned = [];
        // Other failures propagate: then the fate of the rest is unknown.
        $awaitUntil = static function (int $window) use ($pending, &$nacked, &$returned) {
            while ($pending->count() > $window) {
                [$publish, $confirm] = $pending->dequeue();

                try {
                    $confirm->await();
                } catch (PublishNacked) {
                    $nacked[] = $publish;
                } catch (MessageReturned) {
                    $returned[] = $publish;
                }
            }
        };
        $buffer = new Buffer();
        /** @var list<array{Publish, ?string}> $chunk with the return id of a mandatory message */
        $chunk = [];

        foreach ($publishes as $publish) {
            $chunk[] = [$publish, $this->encodePublish($buffer, $publish)];

            if ($buffer->count() < self::BATCH_BYTES) {
                continue;
            }

            foreach ($this->writePublishes($buffer->drain(), $chunk) as $written) {
                $pending->enqueue($written);
            }
            $chunk = [];

            $awaitUntil(self::BATCH_WINDOW);
        }

        if ($chunk !== []) {
            foreach ($this->writePublishes($buffer->drain(), $chunk) as $written) {
                $pending->enqueue($written);
            }
        }

        $awaitUntil(0);

        if ($nacked !== [] || $returned !== []) {
            throw new PublishBatchFailed($nacked, $returned);
        }
    }

    /**
     * @throws AmqpException
     * @throws \LogicException if the channel confirms publishes
     */
    public function txSelect(): void
    {
        shield(fn() => $this->selectMode(new TxSelect()));
    }

    /**
     * @throws AmqpException
     * @throws \LogicException if the channel is not transactional
     */
    public function txCommit(): void
    {
        $this->writing(function () {
            $this->ensureTransactional();
            $this->call(new TxCommit(), TxCommitOk::class);
        });
    }

    /**
     * @throws AmqpException
     * @throws \LogicException if the channel is not transactional
     */
    public function txRollback(): void
    {
        $this->writing(function () {
            $this->ensureTransactional();
            $this->call(new TxRollback(), TxRollbackOk::class);
        });
    }

    /**
     * Runs $fn in a transaction: commits if it returns, rolls back if it throws, a cancellation included.
     * Selects the transaction mode first if needed.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     * @throws AmqpException
     */
    public function transactional(callable $fn): mixed
    {
        $lock = $this->acquireWrite();

        if ($this->activeTransaction !== null) {
            $lock->release();

            throw new \LogicException('AMQP transactions cannot be nested on one channel.');
        }

        /** @var Deferred<null> $transaction */
        $transaction = $this->activeTransaction = new Deferred();
        $lock->release();

        try {
            return $this->transaction->with($transaction, fn() => $this->runTransaction($fn));
        } finally {
            $this->activeTransaction = null;
            $transaction->complete();
        }
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    private function runTransaction(callable $fn): mixed
    {
        $this->txSelect();

        try {
            $result = $fn($this);
        } catch (\Throwable $exception) {
            if (!$this->isClosed) {
                try {
                    // Shielded: a cancelled $fn must not leave its publishes to the next commit.
                    shield($this->txRollback(...));
                } catch (AmqpException) {
                    // The channel is closed meanwhile, and the transaction with it: $exception is what matters.
                }
            }

            throw $exception;
        }

        shield($this->txCommit(...));

        return $result;
    }

    /**
     * Closes the channel: its consumers end, publishes waiting for a confirm throw {@see ChannelClosed}.
     * Idempotent, and does nothing if the channel is already closed.
     */
    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        try {
            $close = new ChannelClose(ReplyCode::ReplySuccess->value, 'Closed by the client.', 0, 0);
            $this->call($close, ChannelCloseOk::class);
        } catch (AmqpException) {
            // Closed by the server or with the connection meanwhile: closed anyway.
        }
    }

    /**
     * @no-named-arguments
     * @param class-string<Method> ...$replies
     * @throws AmqpException
     */
    private function call(Method $method, string ...$replies): null|Method|Delivery
    {
        return $this->sendCall($this->callMutex->acquire(), $method, $replies);
    }

    /**
     * @param list<class-string<Method>> $replies
     * @throws AmqpException
     */
    private function sendCall(
        Lock $lock,
        Method $method,
        array $replies,
        ?ConsumerState $cancelled = null,
    ): null|Method|Delivery {
        if ($this->closed !== null) {
            $lock->release();

            throw $this->closed;
        }

        $pending = new PendingCall(
            replies: $replies,
            lock: $lock,
            getNoAck: $method instanceof BasicGet && $method->noAck,
            cancelled: $cancelled,
        );
        $this->pending = $pending;

        try {
            $this->connection->send($this->id, $method);
        } catch (\Throwable $exception) {
            if ($this->pending === $pending) {
                $this->pending = null;
            }

            $lock->release();

            throw $exception;
        }

        return $pending->reply->await();
    }

    private function respond(null|Method|Delivery $value): void
    {
        $pending = $this->pending;
        $this->pending = null;
        $pending?->complete($value);
    }

    /**
     * @throws ProtocolError
     */
    private function handle(null|Method|ContentHeader|string $frame): void
    {
        if ($frame === null) {
            return;
        }

        if (\is_string($frame)) {
            $this->receiveContent($this->content->receiveBody($frame));

            return;
        }

        if ($frame instanceof ContentHeader) {
            $this->receiveContent($this->content->receiveHeader($frame));

            return;
        }

        if ($this->content->isStarted) {
            throw new ProtocolError(
                replyCode: ReplyCode::UnexpectedFrame,
                message: "A method in the middle of a content on channel {$this->id}.",
            );
        }

        if ($frame instanceof BasicDeliver || $frame instanceof BasicReturn) {
            $this->content->start($frame);

            return;
        }

        if ($frame instanceof BasicAck || $frame instanceof BasicNack) {
            $this->confirms->confirm($frame->deliveryTag, $frame->multiple, nacked: $frame instanceof BasicNack);

            return;
        }

        if ($frame instanceof ChannelClose) {
            $this->closedByServer($frame);

            return;
        }

        if ($frame instanceof BasicCancel) {
            $this->cancelledByServer($frame);

            return;
        }

        if ($frame instanceof ChannelFlow) {
            // RabbitMQ never stops a client this way: acknowledge and go on.
            $this->connection->send($this->id, new ChannelFlowOk($frame->active));

            return;
        }

        if ($this->pending === null || !$this->pending->expects($frame)) {
            throw new ProtocolError(
                replyCode: ReplyCode::UnexpectedFrame,
                message: \sprintf('Unexpected %s on channel %d.', $frame::class, $this->id),
            );
        }

        if ($frame instanceof BasicGetOk) {
            $this->content->start($frame);

            return;
        }

        if ($frame instanceof BasicCancelOk) {
            $consumer = $this->consumers[$frame->consumerTag] ?? null;

            if ($consumer === $this->pending->cancelled) {
                $this->endConsumer($frame->consumerTag, null);
            }
        }

        $this->respond($frame instanceof BasicGetEmpty ? null : $frame);

        if ($frame instanceof ChannelCloseOk) {
            $this->terminate(new ChannelClosed(
                message: "Channel {$this->id} is closed by the client.",
                replyCode: ReplyCode::ReplySuccess,
            ));
        }
    }

    /**
     * @param ?array{BasicDeliver|BasicGetOk|BasicReturn, Message} $content null while the message is incomplete
     */
    private function receiveContent(?array $content): void
    {
        if ($content === null) {
            return;
        }

        [$method, $message] = $content;

        if ($method instanceof BasicReturn) {
            $this->confirms->receiveReturn($method, $message);

            return;
        }

        $consumerTag = $method instanceof BasicDeliver ? $method->consumerTag : null;
        $consumer = $consumerTag === null ? null : $this->consumers[$consumerTag] ?? null;
        $noAck = $consumerTag === null ? $this->pending->getNoAck ?? false : $consumer->noAck ?? true;
        $delivery = new Delivery(
            settle: $this->settle(...),
            reply: $this->publishReply(...),
            message: $message,
            exchange: $method->exchange,
            routingKey: $method->routingKey,
            deliveryTag: $method->deliveryTag,
            redelivered: $method->redelivered,
            consumerTag: $consumerTag,
            isSettled: $noAck,
        );

        if ($consumerTag === null) {
            $this->respond($delivery);

            return;
        }

        // A delivery for a consumer that is being cancelled: it is requeued when the channel closes.
        $consumer?->deliveries->trySend($delivery);
    }

    /**
     * The tags are assigned and the bytes written under the mutex, in one order. Its wait may outlive the channel.
     *
     * @param non-empty-list<array{Publish, ?string}> $chunk pairs of a publish and its return ID. The ID is null
     *     for a non-mandatory message.
     * @return list<array{Publish, Deferred<null>}> empty without confirms
     * @throws AmqpException
     */
    private function writePublishes(string $bytes, array $chunk): array
    {
        do {
            $this->connection->awaitUnblocked($this->id, $this->ensureOpen(...));
            $written = $this->writing(fn() => $this->tryPublish($bytes, $chunk));
        } while ($written === null);

        return $written;
    }

    /**
     * @param non-empty-list<array{Publish, ?string}> $chunk
     * @return ?list<array{Publish, Deferred<null>}> null if a new resource alarm prevented the write
     * @throws AmqpException
     */
    private function tryPublish(string $bytes, array $chunk): ?array
    {
        $this->ensureOpen();

        if (!$this->mode instanceof ConfirmSelect) {
            return $this->connection->tryPublish($bytes, $this->ensureOpen(...)) ? [] : null;
        }

        $confirms = $this->confirms->expect($chunk);

        $written = false;

        try {
            $written = $this->connection->tryPublish($bytes, $this->ensureOpen(...));
        } finally {
            // A failed or blocked write that sent no bytes leaves its tags for the next publisher.
            if (!$written && $this->connection->isIntact()) {
                $this->confirms->giveBack(\count($confirms));
            }
        }

        return $written ? $confirms : null;
    }

    /**
     * @throws AmqpException
     */
    private function settle(BasicAck|BasicNack|BasicReject $method): void
    {
        $this->writing(function () use ($method) {
            $this->ensureOpen();

            if ($method->deliveryTag <= $this->settledUpTo) {
                return;
            }

            $this->connection->send($this->id, $method);

            if (!$method instanceof BasicReject && $method->multiple) {
                $this->settledUpTo = $method->deliveryTag;
            }
        });
    }

    /**
     * @throws AmqpException
     */
    private function publishReply(Message $message, string $replyTo): void
    {
        $this->publish($message, routingKey: $replyTo);
    }

    private function cancelConsumer(string $tag, ConsumerState $consumer): void
    {
        $lock = $this->callMutex->acquire();

        if (($this->consumers[$tag] ?? null) !== $consumer) {
            $lock->release();

            return;
        }

        try {
            $this->sendCall($lock, new BasicCancel($tag, noWait: false), [BasicCancelOk::class], $consumer);
        } catch (AmqpException) {
            // The channel is closed meanwhile, and so is the consumer.
        }
    }

    private function cancelledByServer(BasicCancel $cancel): void
    {
        $this->endConsumer(
            $cancel->consumerTag,
            new ConsumerCancelled(
                message: "The server has cancelled consumer \"{$cancel->consumerTag}\": its queue is gone or has moved.",
            ),
        );

        if (!$cancel->noWait) {
            $this->connection->send($this->id, new BasicCancelOk($cancel->consumerTag));
        }
    }

    private function endConsumer(string $tag, ?AmqpException $reason): void
    {
        $consumer = $this->consumers[$tag] ?? null;
        unset($this->consumers[$tag]);
        $consumer?->deliveries->close($reason);
    }

    private function closedByServer(ChannelClose $close): void
    {
        try {
            $this->connection->send($this->id, new ChannelCloseOk());
        } catch (ConnectionClosed) {
            // The channel goes with the connection.
        }

        $this->terminate(new ChannelClosed(
            message: "The server has closed channel {$this->id}: {$close->replyCode} {$close->replyText}.",
            replyCode: ReplyCode::tryFrom($close->replyCode),
            classId: $close->classId,
            methodId: $close->methodId,
        ));
    }

    /**
     * Called by the connection for its own end too. A close by the client ends the consumers normally,
     * any other reason is thrown from their iteration.
     */
    private function terminate(AmqpException $reason): void
    {
        if ($this->closed !== null) {
            return;
        }

        $this->closed = $reason;
        $this->isClosed = true;
        $this->connection->unregister($this->id);

        $pending = $this->pending;
        $this->pending = null;
        $pending?->fail($reason);

        $graceful = ($reason instanceof ChannelClosed || $reason instanceof ConnectionClosed)
            && $reason->replyCode === ReplyCode::ReplySuccess;

        foreach (array_keys($this->consumers) as $tag) {
            $this->endConsumer((string) $tag, $graceful ? null : $reason);
        }

        $this->confirms->failAll($reason);
    }

    /**
     * Before the connection closes: the consumers end, and the publishes in flight get their confirms.
     */
    private function drain(): void
    {
        foreach ($this->consumers as $tag => $consumer) {
            $this->cancelConsumer((string) $tag, $consumer);
        }

        $this->confirms->awaitAll();
    }

    /**
     * @return ?string the message's return ID if mandatory, or null otherwise
     * @throws \LogicException if the message is mandatory and confirms are disabled
     * @throws \ValueError
     */
    private function encodePublish(Buffer $buffer, Publish $publish): ?string
    {
        $message = $publish->message;
        $returnId = null;

        if ($publish->mandatory) {
            if (!$this->mode instanceof ConfirmSelect) {
                // A confirm.select may already be on the wire.
                $this->writing(function () {
                    if (!$this->mode instanceof ConfirmSelect) {
                        throw new \LogicException('A mandatory message needs confirms: call confirmSelect() first.');
                    }
                });
            }

            [$message, $returnId] = Confirms::returnable($message);
        }

        $method = new BasicPublish($publish->exchange, $publish->routingKey, $publish->mandatory, immediate: false);
        Frames::method($buffer, $this->id, $method);
        Frames::content($buffer, $this->id, properties($message), $message->body, $this->connection->bodyFrameMax);

        return $returnId;
    }

    /**
     * @throws AmqpException
     */
    private function ensureOpen(): void
    {
        if ($this->closed !== null) {
            throw $this->closed;
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function writing(callable $fn): mixed
    {
        $lock = $this->acquireWrite();

        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Publish, settle and mode changes must agree on the order of the wire. While a managed transaction
     * is active only its callback and the tasks it started may write. Check the owner after acquiring the mutex:
     * a waiting child can outlive the callback that started it.
     */
    private function acquireWrite(): Lock
    {
        while (true) {
            $lock = $this->writeMutex->acquire();
            $transaction = $this->activeTransaction;

            if ($transaction === null || $transaction === $this->transaction->get()) {
                return $lock;
            }

            $lock->release();
            $transaction->await();
        }
    }

    private function selectMode(ConfirmSelect|TxSelect $mode): void
    {
        $this->writing(function () use ($mode) {
            $this->ensureOpen();

            if ($this->mode instanceof $mode) {
                return;
            }

            if ($this->mode instanceof ConfirmSelect) {
                throw new \LogicException("Channel {$this->id} confirms publishes: it cannot be transactional.");
            }

            if ($this->mode instanceof TxSelect) {
                throw new \LogicException("Channel {$this->id} is transactional: it cannot confirm publishes.");
            }

            $this->call($mode, $mode instanceof ConfirmSelect ? ConfirmSelectOk::class : TxSelectOk::class);
            $this->mode = $mode;
        });
    }

    /**
     * @throws \LogicException
     */
    private function ensureTransactional(): void
    {
        if (!$this->mode instanceof TxSelect) {
            throw new \LogicException("Channel {$this->id} is not transactional: call txSelect() first.");
        }
    }
}
