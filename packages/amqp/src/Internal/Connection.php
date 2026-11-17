<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\AmqpException;
use Thesis\Amqp\Channel;
use Thesis\Amqp\ChannelClosed;
use Thesis\Amqp\Config;
use Thesis\Amqp\ConnectionClosed;
use Thesis\Amqp\Internal\Protocol\ConnectionBlocked;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionUnblocked;
use Thesis\Amqp\Internal\Protocol\ConnectionUpdateSecretOk;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\ReplyCode;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Io\Buffer;
use Thesis\Io\IoException;
use Thesis\Net\Socket;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\ms;

/**
 * One AMQP connection over one socket: the handshake, the reader that dispatches frames to the channels,
 * heartbeats both ways. It is never reused: once closed, the client opens a new one.
 *
 * @internal
 */
final class Connection
{
    /**
     * Why the connection is closed, null while it is open.
     */
    public private(set) ?ConnectionClosed $closed = null;

    /**
     * @var array<int, ChannelHandler>
     */
    private array $channels = [];

    /**
     * @var positive-int
     */
    private int $nextChannel = 1;

    /**
     * Whether a frame has come or gone since the last tick of the heartbeats: they cost nothing per frame.
     */
    private bool $received = false;

    private bool $sent = false;

    /**
     * Writes in progress: while one waits for the socket, a heartbeat would only queue behind it.
     */
    private int $writing = 0;

    /**
     * Set while the server blocks publishing (a resource alarm): publishes wait for it.
     *
     * @var ?Deferred<null>
     */
    private ?Deferred $unblocked = null;

    /**
     * @var ?Deferred<null>
     */
    private ?Deferred $closeOk = null;

    /**
     * @param positive-int $channelMax
     * @param non-negative-int $frameMax 0 if unlimited
     * @param non-negative-int $heartbeat seconds, 0 if off
     */
    private function __construct(
        private readonly Socket $socket,
        private readonly Buffer $in,
        public readonly int $channelMax,
        public readonly int $frameMax,
        private readonly int $heartbeat,
    ) {}

    /**
     * Performs the {@see Handshake} on a connected socket. Closes the socket if it fails.
     *
     * @throws ConnectionClosed
     */
    public static function open(Socket $socket, Config $config): self
    {
        try {
            [$in, $tune] = Handshake::perform($socket, $config);
        } catch (IoException|ProtocolError $exception) {
            $socket->close();

            throw new ConnectionClosed(
                message: "The handshake failed: {$exception->getMessage()}",
                previous: $exception,
            );
        } catch (\Throwable $exception) {
            $socket->close();

            throw $exception;
        }

        return new self(
            socket: $socket,
            in: $in,
            channelMax: $tune->channelMax === 0 ? 0xFFFF : $tune->channelMax,
            frameMax: $tune->frameMax,
            heartbeat: $tune->heartbeat,
        );
    }

    /**
     * The size of the body frames: the negotiated maximum, or that of RabbitMQ when there is none.
     *
     * @return positive-int
     */
    public function bodyFrameMax(): int
    {
        return $this->frameMax === 0 ? Frames::DEFAULT_MAX : max(Frames::OVERHEAD + 1, $this->frameMax);
    }

    /**
     * Reads and dispatches frames, and sends heartbeats, until the connection is closed or lost.
     * Never throws for that: the reason is {@see $closed}, and every channel has got it.
     */
    public function run(): void
    {
        try {
            group(function (Group $group) {
                if ($this->heartbeat > 0) {
                    $group->daemon($this->heartbeats(...));
                }

                while ($this->closed === null) {
                    [$channel, $frame] = Frames::read($this->socket, $this->in, $this->frameMax);
                    $this->received = true;

                    if ($channel === 0) {
                        $this->handle($frame);
                    } else {
                        $handler = $this->channels[$channel] ?? null;

                        if ($handler !== null) {
                            ($handler->handle)($frame);
                        }
                    }
                }
            });
        } catch (ProtocolError $error) {
            $this->fail($error);
        } catch (ConnectionClosed) {
            // A reply of the reader itself (close-ok, cancel-ok, flow-ok) has found the connection lost: terminated.
        } catch (IoException $exception) {
            $this->terminate(new ConnectionClosed(
                message: "The connection is lost: {$exception->getMessage()}",
                previous: $exception,
            ));
        } finally {
            $this->terminate(new ConnectionClosed('The client is stopped.'));
        }
    }

    /**
     * @param int<1, 65535> $channel
     * @param \Closure(Method|ContentHeader|string|null): void $handle throws ProtocolError for a frame out of place
     * @param \Closure(AmqpException): void $terminate
     * @param \Closure(): void $drain
     */
    public function register(int $channel, \Closure $handle, \Closure $terminate, \Closure $drain): void
    {
        $this->channels[$channel] = new ChannelHandler($handle, $terminate, $drain);
    }

    public function unregister(int $channel): void
    {
        unset($this->channels[$channel]);
    }

    /**
     * @throws ConnectionClosed
     * @throws AmqpException if there is no free channel id
     */
    public function openChannel(): Channel
    {
        $this->ensureOpen();

        return new Channel($this, $this->allocateChannel());
    }

    /**
     * @param int<0, 65535> $channel
     * @throws ConnectionClosed
     * @throws \ValueError if a field cannot be encoded
     */
    public function send(int $channel, Method $method): void
    {
        $buffer = new Buffer();
        Frames::method($buffer, $channel, $method);
        $this->write($buffer->drain());
    }

    /**
     * A write that is cancelled after a part of its bytes went out closes the socket (see thesis/net),
     * and the reader then reports the connection as lost. Cancelled before anything went out, it changes nothing.
     *
     * @throws ConnectionClosed
     */
    public function write(string $bytes): void
    {
        $this->ensureOpen();
        ++$this->writing;

        try {
            $this->socket->write($bytes);
        } catch (IoException $exception) {
            $this->terminate(new ConnectionClosed(
                message: "The connection is lost: {$exception->getMessage()}",
                previous: $exception,
            ));

            $this->ensureOpen();
        } finally {
            --$this->writing;
        }

        $this->sent = true;
    }

    /**
     * Whether a write that has thrown left the connection as it was: then nothing of it went out.
     */
    public function isIntact(): bool
    {
        return $this->closed === null && !$this->socket->isClosed;
    }

    /**
     * Waits while the server blocks publishing.
     *
     * @throws ConnectionClosed
     */
    public function awaitUnblocked(): void
    {
        $this->unblocked?->await();
        $this->ensureOpen();
    }

    /**
     * @throws ConnectionClosed
     */
    public function ensureOpen(): void
    {
        if ($this->closed !== null) {
            throw $this->closed;
        }
    }

    /**
     * Cancels the consumers, waits for the confirms that are due, then closes the connection with the server.
     * The caller bounds it with a timeout. A connection that is already closed is left as it is.
     */
    public function close(): void
    {
        foreach ($this->channels as $handler) {
            try {
                ($handler->drain)();
            } catch (AmqpException) {
                // A channel that fails now is closed anyway.
            }
        }

        if ($this->closed !== null) {
            return;
        }

        /** @var Deferred<null> $closeOk */
        $closeOk = new Deferred();
        $this->closeOk = $closeOk;

        try {
            $this->send(0, new ConnectionClose(ReplyCode::ReplySuccess->value, 'Closed by the client.', 0, 0));
        } catch (ConnectionClosed) {
            return;
        }

        $closeOk->await();
    }

    /**
     * Closes the socket and fails every channel with the reason. Only the first reason counts.
     */
    public function terminate(ConnectionClosed $reason): void
    {
        if ($this->closed !== null) {
            return;
        }

        $this->closed = $reason;
        $this->socket->close();

        $channels = $this->channels;
        $this->channels = [];

        foreach ($channels as $handler) {
            ($handler->terminate)($reason);
        }

        $this->unblocked?->complete();
        $this->unblocked = null;
        $this->closeOk?->complete();
    }

    private function handle(null|Method|ContentHeader|string $frame): void
    {
        match (true) {
            $frame === null, $frame instanceof ConnectionUpdateSecretOk => null,
            $frame instanceof ConnectionClose => $this->closedByServer($frame),
            $frame instanceof ConnectionCloseOk => $this->terminate(
                new ConnectionClosed('The connection is closed by the client.', ReplyCode::ReplySuccess),
            ),
            $frame instanceof ConnectionBlocked => $this->unblocked ??= new Deferred(),
            $frame instanceof ConnectionUnblocked => $this->unblock(),
            default => throw new ProtocolError(ReplyCode::UnexpectedFrame, Frames::describe($frame) . ' on channel 0.'),
        };
    }

    private function closedByServer(ConnectionClose $close): void
    {
        try {
            $this->send(0, new ConnectionCloseOk());
        } catch (ConnectionClosed) {
            // The server closes the socket anyway.
        }

        $this->terminate(new ConnectionClosed(
            message: "The server has closed the connection: {$close->replyCode} {$close->replyText}.",
            replyCode: ReplyCode::tryFrom($close->replyCode),
        ));
    }

    private function unblock(): void
    {
        $this->unblocked?->complete();
        $this->unblocked = null;
    }

    /**
     * Tells the server what is wrong, as the protocol asks, then closes.
     */
    private function fail(ProtocolError $error): void
    {
        try {
            $this->send(0, new ConnectionClose($error->replyCode->value, $error->getMessage(), 0, 0));
        } catch (ConnectionClosed) {
            // Closed anyway below.
        }

        $this->terminate(new ConnectionClosed(
            message: "The server has violated the protocol: {$error->getMessage()}",
            replyCode: $error->replyCode,
            previous: $error,
        ));
    }

    /**
     * Every half of the interval: a heartbeat if nothing else went out, and the connection is dead
     * after two intervals without a frame from the server, as RabbitMQ decides for a client.
     */
    private function heartbeats(): void
    {
        $halfInterval = ms($this->heartbeat * 500);
        $silentHalves = 0;

        while ($this->closed === null) {
            delay($halfInterval);

            // Checked before the heartbeat is written: that write may wait for a peer that is gone.
            if ($this->received) {
                $silentHalves = 0;
            } elseif (++$silentHalves >= 4) {
                $this->terminate(new ConnectionClosed(
                    message: \sprintf(
                        'The server has sent nothing for %d seconds: heartbeats are missed.',
                        $this->heartbeat * 2,
                    ),
                ));

                return;
            }

            $this->received = false;

            if (!$this->sent && $this->writing === 0) {
                $buffer = new Buffer();
                Frames::heartbeat($buffer);

                try {
                    $this->write($buffer->drain());
                } catch (ConnectionClosed) {
                    return;
                }
            }

            $this->sent = false;
        }
    }

    /**
     * @return int<1, 65535>
     * @throws AmqpException
     */
    private function allocateChannel(): int
    {
        for ($tries = 0; $tries < $this->channelMax; ++$tries) {
            $channel = $this->nextChannel;
            $this->nextChannel = $channel >= $this->channelMax ? 1 : $channel + 1;

            if (!isset($this->channels[$channel])) {
                /** @var int<1, 65535> */
                return $channel;
            }
        }

        throw new ChannelClosed(
            message: "No channel can open: all {$this->channelMax} channels of the connection are open.",
            replyCode: null,
        );
    }
}
