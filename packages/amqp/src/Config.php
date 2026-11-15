<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Net\Certificate;
use Thesis\Net\ClientTls;
use Time\Duration;
use Uri\WhatWg\Url;
use function Thesis\Amqp\Internal\address;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

/**
 * @api
 */
final readonly class Config
{
    public Duration $heartbeat;

    public Duration $connectTimeout;

    /**
     * @param non-empty-list<non-empty-string> $hosts tried in order: `host`, `host:port`, `[::1]:port`.
     *     The port is 5672, or 5671 with TLS
     * @param ?ClientTls $tls for amqps; the host is the default peer name
     * @param non-empty-list<AuthMechanism> $authMechanisms the first one the server supports is used
     * @param ?Duration $heartbeat 60 seconds if null. The lower of it and the server's is used, zero turns
     *     heartbeats off only if the server agrees. Two intervals without a frame from the server close the connection
     * @param ?Duration $connectTimeout for each attempt to connect to a host and to perform the handshake,
     *     30 seconds if null. The first connection is bounded by the caller of {@see Client::run()} too
     * @param int<0, 65535> $channelMax 0 to take the limit of the server
     * @param int<0, 4294967295> $frameMax 0 to take the limit of the server
     * @param ?string $connectionName shown in the management UI of RabbitMQ
     * @param bool $reconnect whether a lost connection is opened again, with a backoff from 100 ms to 30 s
     * @throws \InvalidArgumentException if a host is malformed
     */
    public function __construct(
        public array $hosts = ['localhost'],
        #[\SensitiveParameter]
        public string $user = 'guest',
        #[\SensitiveParameter]
        public string $password = 'guest',
        public string $vhost = '/',
        public ?ClientTls $tls = null,
        public array $authMechanisms = [AuthMechanism::Plain, AuthMechanism::AmqPlain],
        ?Duration $heartbeat = null,
        ?Duration $connectTimeout = null,
        public int $channelMax = 0,
        public int $frameMax = 0,
        public ?string $connectionName = null,
        public bool $reconnect = true,
    ) {
        // Checked now, not on a reconnect in a daemon, where it would fail the group of the application.
        foreach ($hosts as $host) {
            address($host, 0);
        }
        $this->heartbeat = $heartbeat ?? sec(60);
        $this->connectTimeout = $connectTimeout ?? sec(30);
    }

    /**
     * Parses a URI of the RabbitMQ format, with several hosts separated by commas:
     * `amqps://user:password@host1:5671,host2:5671/vhost?heartbeat=10&connection_timeout=5000`.
     *
     * The query parameters are those of RabbitMQ: `heartbeat` (seconds), `connection_timeout` (milliseconds),
     * `channel_max`, `frame_max`, `auth_mechanism` (repeated for several), `verify` (`verify_none`), `cacertfile`,
     * `certfile`, `keyfile`, `server_name_indication`, and `connection_name`. Others are ignored.
     * The vhost is the path without its first slash, percent-decoded: `/%2F` is `/`, and so is no path.
     *
     * @see https://www.rabbitmq.com/docs/uri-spec
     * @see https://www.rabbitmq.com/docs/uri-query-parameters
     * @throws \InvalidArgumentException if the URI is not one of AMQP
     */
    public static function fromUri(#[\SensitiveParameter] string $uri): self
    {
        if (preg_match('~^(amqps?)://([^/?#]*)(.*)$~i', $uri, $parts) !== 1) {
            throw new \InvalidArgumentException('The URI must start with amqp:// or amqps://.');
        }

        [, $scheme, $authority, $rest] = $parts;
        $scheme = strtolower($scheme);
        $at = strrpos($authority, '@');
        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hosts = explode(',', $at === false ? $authority : substr($authority, $at + 1));

        // The WHATWG parser takes one host: the others are parsed on their own.
        $url = Url::parse("{$scheme}://{$userInfo}" . ($hosts[0] === '' ? 'localhost' : $hosts[0]) . $rest)
            ?? throw new \InvalidArgumentException('The URI is malformed.');
        $query = self::query($url->getQuery() ?? '');
        $tls = $scheme === 'amqps' ? self::tls($query) : null;

        $mechanisms = [];

        foreach ($query['auth_mechanism'] ?? [] as $mechanism) {
            $mechanisms[] = AuthMechanism::tryFrom(strtoupper($mechanism))
                ?? throw new \InvalidArgumentException("Unknown auth mechanism \"{$mechanism}\".");
        }

        $path = $url->getPath();
        $vhost = $path === '' || $path === '/' ? '/' : rawurldecode(substr($path, 1));

        return new self(
            hosts: array_map(self::host(...), $hosts),
            user: self::orDefault(rawurldecode($url->getUsername() ?? ''), 'guest'),
            password: self::orDefault(rawurldecode($url->getPassword() ?? ''), 'guest'),
            vhost: $vhost,
            tls: $tls,
            authMechanisms: $mechanisms === [] ? [AuthMechanism::Plain, AuthMechanism::AmqPlain] : $mechanisms,
            heartbeat: isset($query['heartbeat']) ? sec(self::int($query, 'heartbeat')) : null,
            connectTimeout: isset($query['connection_timeout']) ? ms(self::int($query, 'connection_timeout')) : null,
            channelMax: min(65535, self::int($query, 'channel_max')),
            frameMax: min(4_294_967_295, self::int($query, 'frame_max')),
            connectionName: $query['connection_name'][0] ?? null,
        );
    }

    private static function orDefault(string $value, string $default): string
    {
        return $value === '' ? $default : $value;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private static function query(string $query): array
    {
        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strstr($pair, '=', before_needle: true);
            $value = $name === false ? '' : substr($pair, \strlen($name) + 1);
            $parameters[urldecode($name === false ? $pair : $name)][] = urldecode($value);
        }

        return $parameters;
    }

    /**
     * @param array<string, non-empty-list<string>> $query
     * @return non-negative-int
     */
    private static function int(array $query, string $name): int
    {
        $value = $query[$name][0] ?? '0';

        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($int === false) {
            throw new \InvalidArgumentException(
                message: "The {$name} of the URI must be a non-negative integer, got \"{$value}\".",
            );
        }

        return $int;
    }

    /**
     * @param array<string, non-empty-list<string>> $query
     */
    private static function tls(array $query): ClientTls
    {
        $verify = ($query['verify'][0] ?? 'verify_peer') !== 'verify_none';
        $certFile = $query['certfile'][0] ?? '';
        $keyFile = $query['keyfile'][0] ?? '';
        $caFile = $query['cacertfile'][0] ?? '';
        $peerName = $query['server_name_indication'][0] ?? '';

        return new ClientTls(
            peerName: $peerName === '' ? null : $peerName,
            verifyPeer: $verify,
            verifyPeerName: $verify,
            caFile: $caFile === '' ? null : $caFile,
            certificate: $certFile === '' ? null : new Certificate($certFile, $keyFile === '' ? null : $keyFile),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function host(string $host): string
    {
        if ($host === '') {
            return 'localhost';
        }

        $url = Url::parse("amqp://{$host}")
            ?? throw new \InvalidArgumentException("The host \"{$host}\" is malformed.");
        $name = $url->getAsciiHost();

        if ($name === null || $name === '') {
            throw new \InvalidArgumentException("The host \"{$host}\" is malformed.");
        }

        $port = $url->getPort();

        return $port === null ? $name : "{$name}:{$port}";
    }
}
