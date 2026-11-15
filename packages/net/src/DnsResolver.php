<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\StreamWatcher;
use Thesis\Io\Buffer;
use Thesis\Io\IoException;
use Thesis\Net\Internal\Connector;
use Thesis\Net\Internal\Dns\Cached;
use Thesis\Net\Internal\Dns\Message;
use Thesis\Net\Internal\Dns\Response;
use Thesis\Net\Internal\Dns\SystemConfig;
use function Thesis\Async\allSettled;
use function Thesis\Async\now;
use function Thesis\Async\timeoutOr;
use function Thesis\Net\Internal\asciiHost;
use function Thesis\Net\Internal\lastError;

/**
 * A stub resolver, like the one of glibc, that never blocks the event loop: /etc/hosts first, then A and AAAA queries
 * to the name servers, over UDP and over TCP if the answer does not fit. Answers are cached for their TTL.
 *
 * @api
 */
final class DnsResolver implements Resolver
{
    private const int MAX_CACHED = 1024;

    /**
     * A day, as most resolvers cap it: a record with a huge TTL must not stay in a long-running process for good.
     */
    private const int MAX_TTL = 86_400;

    /**
     * The resolver configured by /etc/resolv.conf and /etc/hosts, read once.
     */
    public static function system(): self
    {
        static $resolver = self::fromFiles();

        /** @var self */
        return $resolver;
    }

    /**
     * A resolver configured by files in the formats of /etc/resolv.conf and /etc/hosts.
     * Only what affects host names is taken: nameserver, search, domain and the options ndots, timeout, attempts.
     * A missing file is as if empty: with no nameserver, 127.0.0.1 is asked.
     */
    public static function fromFiles(string $resolvConf = '/etc/resolv.conf', string $hosts = '/etc/hosts'): self
    {
        $config = SystemConfig::load($resolvConf, $hosts);

        return new self(
            nameservers: $config->nameservers,
            hosts: $config->hosts,
            search: $config->search,
            ndots: $config->ndots,
            timeout: $config->timeout,
            attempts: $config->attempts,
        );
    }

    /**
     * @var array<non-empty-string, Cached>
     */
    private array $cache = [];

    /**
     * @param non-empty-list<InetAddress> $nameservers asked in this order, the next one if a server fails or is silent
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $hosts lower-case host name => its addresses,
     *     as in /etc/hosts
     * @param list<non-empty-string> $search domains appended to a name with fewer than $ndots dots
     * @param non-negative-int $ndots
     * @param float $timeout seconds to wait for one server to answer
     * @param positive-int $attempts rounds over the servers
     */
    public function __construct(
        private readonly array $nameservers,
        private readonly array $hosts = [],
        private readonly array $search = [],
        private readonly int $ndots = 1,
        private readonly float $timeout = 5,
        private readonly int $attempts = 2,
    ) {}

    /**
     * IPv6 addresses come first: {@see connect()} alternates the families anyway, starting with the first one.
     * An internationalized name is asked for in its ASCII form (UTS #46), which needs ext-intl.
     */
    public function resolve(string $host): array
    {
        $name = asciiHost($host) ?? throw new DnsFailed(\sprintf('"%s" is not a valid host name.', $host));
        $absolute = str_ends_with($name, '.');
        $name = rtrim($name, '.');

        if ($name === '') {
            throw new DnsFailed(\sprintf('"%s" is not a valid host name.', $host));
        }

        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return [$name];
        }

        if (isset($this->hosts[$name])) {
            return $this->hosts[$name];
        }

        // RFC 6761: localhost is loopback, whatever the name servers say.
        if ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            return ['::1', '127.0.0.1'];
        }

        $cached = $this->cache[$name] ?? null;

        if ($cached !== null && $cached->expiresAt > now()) {
            return $cached->addresses;
        }

        $errors = [];

        foreach ($this->candidates($name, $absolute) as $candidate) {
            [$responses, $failures] = allSettled([
                fn() => $this->query($candidate, Message::AAAA),
                fn() => $this->query($candidate, Message::A),
            ]);

            $addresses = [];
            $ttl = PHP_INT_MAX;

            foreach ($responses as $response) {
                if ($response->addresses !== []) {
                    $addresses = [...$addresses, ...$response->addresses];
                    $ttl = min($ttl, $response->ttl);
                }
            }

            if ($addresses !== []) {
                $this->store($name, $addresses, $ttl);

                return $addresses;
            }

            foreach ($failures as $failure) {
                if (!$failure instanceof DnsFailed) {
                    throw $failure;
                }

                $errors[] = $failure->getMessage();
            }
        }

        throw new DnsFailed(
            $errors === []
                ? \sprintf('Host "%s" not found.', $host)
                : \sprintf('Failed to resolve "%s": ', $host) . implode('; ', array_unique($errors)),
        );
    }

    /**
     * The names to ask for, in order, as glibc derives them from $search and $ndots.
     *
     * @param non-empty-string $name
     * @return non-empty-list<non-empty-string>
     */
    private function candidates(string $name, bool $absolute): array
    {
        if ($absolute || $this->search === []) {
            return [$name];
        }

        $searched = array_map(static fn(string $domain) => "{$name}.{$domain}", $this->search);

        return substr_count($name, '.') >= $this->ndots ? [$name, ...$searched] : [...$searched, $name];
    }

    /**
     * @param non-empty-string $name
     * @param Message::A|Message::AAAA $type
     * @return Response with no addresses if the name or the record does not exist
     * @throws DnsFailed if no server has answered
     */
    private function query(string $name, int $type): Response
    {
        $errors = [];

        for ($attempt = 0; $attempt < $this->attempts; ++$attempt) {
            foreach ($this->nameservers as $nameserver) {
                $request = Message::query(random_int(0, 0xFFFF), $name, $type);

                try {
                    $response = timeoutOr($this->timeout, static fn() => self::udp($nameserver, $request));

                    if ($response?->truncated === true) {
                        $response = timeoutOr($this->timeout, static fn() => self::tcp($nameserver, $request));
                    }
                } catch (IoException $exception) {
                    $errors[] = "{$nameserver}: {$exception->getMessage()}";

                    continue;
                }

                if ($response === null) {
                    $errors[] = "{$nameserver}: timed out";
                } elseif ($response->rcode === Message::NOERROR || $response->rcode === Message::NXDOMAIN) {
                    return $response;
                } else {
                    $errors[] = $nameserver . ': ' . self::rcodeName($response->rcode);
                }
            }
        }

        throw new DnsFailed("{$name}: " . implode('; ', array_unique($errors)));
    }

    /**
     * @throws IoException
     */
    private static function udp(InetAddress $nameserver, string $request): Response
    {
        error_clear_last();
        // A socket per query: a random source port, along with a random id, protects from spoofed answers.
        $stream = @stream_socket_client(
            address: 'udp://' . $nameserver,
            error_code: $errno,
            error_message: $error,
            timeout: null,
            flags: STREAM_CLIENT_CONNECT,
            context: stream_context_create(),
        );

        if ($stream === false) {
            throw new DnsFailed(
                \is_string($error) && $error !== '' ? $error : lastError('Failed to create a UDP socket.'),
            );
        }

        try {
            stream_set_blocking($stream, false);
            $watcher = new StreamWatcher($stream);
            error_clear_last();

            if (@fwrite($stream, $request) !== \strlen($request)) {
                throw new DnsFailed(lastError('Failed to send the query.'));
            }

            while (true) {
                error_clear_last();
                $bytes = @fread($stream, 65_535);

                if ($bytes === false) {
                    throw new DnsFailed(lastError('Connection refused'));
                }

                if ($bytes === '') {
                    $watcher->readable();

                    continue;
                }

                // A late answer to an earlier query of the same socket cannot happen, but a stray datagram can.
                $response = Message::parse($bytes, $request);

                if ($response !== null) {
                    return $response;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @throws IoException
     */
    private static function tcp(InetAddress $nameserver, string $request): Response
    {
        $socket = Connector::tcp([$nameserver->ip], $nameserver->port, noDelay: true, host: $nameserver->ip);

        try {
            $socket->write(pack('n', \strlen($request)) . $request);
            $buffer = new Buffer();
            $buffer->fill($socket, 2);
            $length = $buffer->readUint16();
            $buffer->fill($socket, $length);
            $response = Message::parse($buffer->read($length), $request);
        } finally {
            $socket->close();
        }

        return $response ?? throw new DnsFailed('The answer is to another query.');
    }

    /**
     * @param int<0, 15> $rcode
     */
    private static function rcodeName(int $rcode): string
    {
        return match ($rcode) {
            1 => 'format error',
            2 => 'server failure',
            4 => 'not implemented',
            5 => 'refused',
            default => \sprintf('response code %d', $rcode),
        };
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-list<non-empty-string> $addresses
     */
    private function store(string $name, array $addresses, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }

        unset($this->cache[$name]);

        if (\count($this->cache) >= self::MAX_CACHED) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        $this->cache[$name] = new Cached(addresses: $addresses, expiresAt: now() + min($ttl, self::MAX_TTL));
    }
}
