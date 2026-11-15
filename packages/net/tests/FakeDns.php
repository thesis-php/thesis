<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Group;
use Thesis\Async\StreamWatcher;
use Thesis\Io\Buffer;
use Thesis\Net\Internal\Dns\Message;

/**
 * A name server on 127.0.0.1, UDP and TCP on the same port, answering from a zone. It runs in daemons of a group.
 */
final class FakeDns
{
    private const int CNAME = 5;

    /**
     * @param Group $group the daemons of the server are cancelled once its body returns
     * @param array<string, Zone> $zone by lower-case name
     */
    public static function start(Group $group, array $zone): self
    {
        $udp = stream_socket_server(
            address: 'udp://127.0.0.1:0',
            error_code: $errno,
            error_message: $error,
            flags: STREAM_SERVER_BIND,
        );
        \assert($udp !== false, $error);
        $name = stream_socket_get_name($udp, false);
        \assert($name !== false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        \assert($port > 0 && $port <= 65535);
        $tcp = listen('127.0.0.1', $port);
        stream_set_blocking($udp, false);
        $address = new InetAddress(ip: '127.0.0.1', port: $port);
        $dns = new self($address, $zone);

        $group->daemon(static function () use ($dns, $udp) {
            try {
                $dns->serveUdp($udp);
            } finally {
                fclose($udp);
            }
        });
        $group->daemon(static function () use ($dns, $tcp) {
            try {
                while (null !== $socket = $tcp->accept()) {
                    $dns->serveTcp($socket);
                }
            } finally {
                $tcp->close();
            }
        });

        return $dns;
    }

    /**
     * @var list<string> "udp db.test AAAA", in the order they came
     */
    public array $queries = [];

    /**
     * @param array<string, Zone> $zone
     */
    private function __construct(
        public readonly InetAddress $address,
        private readonly array $zone,
    ) {}

    /**
     * @param resource $udp
     */
    private function serveUdp(mixed $udp): never
    {
        $watcher = new StreamWatcher($udp);

        while (true) {
            $query = stream_socket_recvfrom($udp, length: 512, flags: 0, address: $peer);

            if ($query === false || $query === '') {
                $watcher->readable();

                continue;
            }

            $response = $this->answer($query, 'udp');

            if ($response !== null) {
                \assert(\is_string($peer));
                stream_socket_sendto($udp, $response, flags: 0, address: $peer);
            }
        }
    }

    private function serveTcp(Socket $socket): void
    {
        try {
            $buffer = new Buffer();
            $buffer->fill($socket, 2);
            $length = $buffer->readUint16();
            $buffer->fill($socket, $length);
            $response = $this->answer($buffer->read($length), 'tcp');

            if ($response !== null) {
                $socket->write(pack('n', \strlen($response)) . $response);
            }
        } finally {
            $socket->close();
        }
    }

    private function answer(string $query, string $transport): ?string
    {
        $buffer = new Buffer(bytes: $query);
        $id = $buffer->readUint16();
        $buffer->skip(10);
        $labels = [];

        while (($length = $buffer->readUint8()) !== 0) {
            $labels[] = $buffer->read($length);
        }

        $type = $buffer->readUint16();
        $name = implode('.', $labels);
        $this->queries[] = "{$transport} {$name} " . ($type === Message::A ? 'A' : 'AAAA');
        $zone = $this->zone[$name] ?? new Zone(rcode: Message::NXDOMAIN);

        if ($zone->silent) {
            return null;
        }

        $truncated = $zone->truncated && $transport === 'udp';
        $records = [];

        if (!$truncated) {
            if ($zone->cname !== null) {
                $target = '';

                foreach (explode('.', $zone->cname) as $label) {
                    $target .= pack('C', \strlen($label)) . $label;
                }

                $records[] = self::record(self::CNAME, $zone->ttl, $target . "\0");
            }

            foreach ($type === Message::A ? $zone->a : $zone->aaaa as $ip) {
                $address = inet_pton($ip);
                \assert($address !== false);
                $records[] = self::record($type, $zone->ttl, $address);
            }
        }

        $question = substr($query, 12);

        return new Buffer()
            ->writeUint16($id)
            ->writeUint16(0x8180 | ($truncated ? 0x0200 : 0) | $zone->rcode)
            ->writeUint16(1)
            ->writeUint16(\count($records))
            ->writeUint16(0)
            ->writeUint16(0)
            ->write($question)
            ->write(implode('', $records))
            ->drain()
        ;
    }

    private static function record(int $type, int $ttl, string $data): string
    {
        // 0xC00C points to the name of the question
        return new Buffer()
            ->writeUint16(0xC00C)
            ->writeUint16($type)
            ->writeUint16(1)
            ->writeUint32($ttl)
            ->writeUint16(\strlen($data))
            ->write($data)
            ->drain()
        ;
    }
}
