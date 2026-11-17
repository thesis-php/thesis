<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\AuthMechanism;
use Thesis\Amqp\Config;
use Thesis\Amqp\ConnectionClosed;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionOpen;
use Thesis\Amqp\Internal\Protocol\ConnectionOpenOk;
use Thesis\Amqp\Internal\Protocol\ConnectionStart;
use Thesis\Amqp\Internal\Protocol\ConnectionStartOk;
use Thesis\Amqp\Internal\Protocol\ConnectionTune;
use Thesis\Amqp\Internal\Protocol\ConnectionTuneOk;
use Thesis\Amqp\Internal\Protocol\Fields;
use Thesis\Amqp\Internal\Protocol\Method;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\ReplyCode;
use Thesis\Io\Buffer;
use Thesis\Io\IoException;
use Thesis\Net\Socket;
use function Thesis\Time\toSec;

/**
 * The opening of a connection: the protocol header, the authentication, the tuning and the virtual host.
 *
 * @internal
 */
final readonly class Handshake
{
    private const array CAPABILITIES = [
        'publisher_confirms' => true,
        'exchange_exchange_bindings' => true,
        'basic.nack' => true,
        'consumer_cancel_notify' => true,
        'connection.blocked' => true,
        'authentication_failure_close' => true,
        'per_consumer_qos' => true,
    ];

    private Buffer $in;

    private function __construct(
        private Socket $socket,
        private Config $config,
    ) {
        $this->in = new Buffer();
    }

    /**
     * @return array{Buffer, ConnectionTuneOk} what the server has sent after the handshake, and the tuning agreed on
     * @throws IoException
     * @throws ProtocolError
     * @throws ConnectionClosed if the server closes the connection or supports none of the mechanisms of the config
     */
    public static function perform(Socket $socket, Config $config): array
    {
        $handshake = new self($socket, $config);

        return [$handshake->in, $handshake->run()];
    }

    /**
     * @throws IoException
     * @throws ProtocolError
     * @throws ConnectionClosed
     */
    private function run(): ConnectionTuneOk
    {
        $this->socket->write(Frames::PROTOCOL_HEADER);
        $start = $this->expect(ConnectionStart::class);
        [$mechanism, $response] = $this->authenticate($start);
        $this->send(new ConnectionStartOk(
            clientProperties: $this->clientProperties(),
            mechanism: $mechanism->value,
            response: $response,
            locale: 'en_US',
        ));

        $tune = $this->expect(ConnectionTune::class);
        /** @var int<0, 65535> $channelMax */
        $channelMax = self::negotiate($this->config->channelMax, $tune->channelMax);
        /** @var int<0, 4294967295> $frameMax */
        $frameMax = self::negotiate($this->config->frameMax, $tune->frameMax);
        // Rounded up: a heartbeat under a second must not become 0, which takes the interval of the server.
        $seconds = toSec($this->config->heartbeat, \RoundingMode::PositiveInfinity);
        /** @var int<0, 65535> $heartbeat */
        $heartbeat = self::negotiate(min(65535, max(0, $seconds)), $tune->heartbeat);
        $tuneOk = new ConnectionTuneOk($channelMax, $frameMax, $heartbeat);
        $this->send($tuneOk);

        $this->send(new ConnectionOpen($this->config->vhost));
        $this->expect(ConnectionOpenOk::class);

        return $tuneOk;
    }

    /**
     * @template T of Method
     * @param class-string<T> $class
     * @return T
     * @throws IoException
     * @throws ProtocolError
     * @throws ConnectionClosed if the server closes the connection instead
     */
    private function expect(string $class): Method
    {
        while (true) {
            // Bounded before the limit is negotiated: a peer that is not a broker must not make us buffer 4 GiB.
            [$channel, $frame] = Frames::read($this->socket, $this->in, Frames::DEFAULT_MAX);

            if ($frame === null) {
                continue;
            }

            if ($channel === 0 && $frame instanceof $class) {
                return $frame;
            }

            if ($frame instanceof ConnectionClose) {
                $this->send(new ConnectionCloseOk());

                throw new ConnectionClosed(
                    message: "The server has closed the connection: {$frame->replyCode} {$frame->replyText}.",
                    replyCode: ReplyCode::tryFrom($frame->replyCode),
                );
            }

            throw new ProtocolError(
                ReplyCode::UnexpectedFrame,
                \sprintf('Expected %s, got %s on channel %d.', $class, Frames::describe($frame), $channel),
            );
        }
    }

    /**
     * @throws IoException
     */
    private function send(Method $method): void
    {
        $buffer = new Buffer();
        Frames::method($buffer, 0, $method);
        $this->socket->write($buffer->drain());
    }

    /**
     * @return array{AuthMechanism, string}
     * @throws ConnectionClosed if the server supports none of the mechanisms of the config
     */
    private function authenticate(ConnectionStart $start): array
    {
        $supported = explode(' ', $start->mechanisms);

        foreach ($this->config->authMechanisms as $mechanism) {
            if (!\in_array($mechanism->value, $supported, true)) {
                continue;
            }

            $response = match ($mechanism) {
                AuthMechanism::Plain => "\0{$this->config->user}\0{$this->config->password}",
                AuthMechanism::AmqPlain => $this->amqPlain(),
                AuthMechanism::External => '',
            };

            return [$mechanism, $response];
        }

        throw new ConnectionClosed(\sprintf(
            'The server supports none of the mechanisms %s, only %s.',
            implode(
                ', ',
                array_map(static fn(AuthMechanism $mechanism) => $mechanism->value, $this->config->authMechanisms),
            ),
            $start->mechanisms,
        ));
    }

    /**
     * A field table without its length.
     */
    private function amqPlain(): string
    {
        $buffer = new Buffer();
        Fields::writeTable($buffer, ['LOGIN' => $this->config->user, 'PASSWORD' => $this->config->password]);
        $buffer->skip(4);

        return $buffer->drain();
    }

    /**
     * @return array<string, mixed>
     */
    private function clientProperties(): array
    {
        $properties = [
            'product' => 'thesis/amqp',
            'platform' => 'PHP ' . PHP_VERSION,
            'capabilities' => self::CAPABILITIES,
        ];

        if ($this->config->connectionName !== null) {
            $properties['connection_name'] = $this->config->connectionName;
        }

        return $properties;
    }

    /**
     * As RabbitMQ: 0 is no limit, so if either side has none, the other one's counts, otherwise the lower one.
     *
     * @param non-negative-int $client
     * @param non-negative-int $server
     * @return non-negative-int
     */
    private static function negotiate(int $client, int $server): int
    {
        if ($client === 0 || $server === 0) {
            return max($client, $server);
        }

        return min($client, $server);
    }
}
