<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

use Thesis\Net\InetAddress;

/**
 * What glibc reads from /etc/resolv.conf and /etc/hosts, as far as resolving a host name goes.
 *
 * @internal
 */
final readonly class SystemConfig
{
    /**
     * glibc uses at most this many name servers.
     */
    private const int MAX_NAMESERVERS = 3;

    public static function load(string $resolvConf = '/etc/resolv.conf', string $hosts = '/etc/hosts'): self
    {
        return self::parse(
            resolvConf: is_readable($resolvConf) ? (string) file_get_contents($resolvConf) : '',
            hosts: is_readable($hosts) ? (string) file_get_contents($hosts) : '',
        );
    }

    public static function parse(string $resolvConf, string $hosts): self
    {
        $nameservers = [];
        $search = [];
        $ndots = 1;
        $timeout = 5;
        $attempts = 2;

        foreach (self::lines($resolvConf) as $fields) {
            $keyword = array_shift($fields);

            if ($keyword === 'nameserver' && isset($fields[0]) && \count($nameservers) < self::MAX_NAMESERVERS) {
                if (filter_var($fields[0], FILTER_VALIDATE_IP) !== false) {
                    $nameservers[] = new InetAddress(ip: $fields[0], port: 53);
                }
            } elseif ($keyword === 'search' || $keyword === 'domain') {
                // The last of them wins, as in glibc.
                $search = array_map(static fn(string $domain) => rtrim($domain, '.'), $fields);
            } elseif ($keyword === 'options') {
                foreach ($fields as $option) {
                    $name = strstr($option, ':', before_needle: true);
                    $value = substr($option, \strlen((string) $name) + 1);

                    if ($name === false || !is_numeric($value)) {
                        continue;
                    }

                    match ($name) {
                        'ndots' => $ndots = min(max((int) $value, 0), 15),
                        'timeout' => $timeout = min(max((int) $value, 1), 30),
                        'attempts' => $attempts = min(max((int) $value, 1), 5),
                        default => null,
                    };
                }
            }
        }

        $hostAddresses = [];

        foreach (self::lines($hosts) as $fields) {
            $ip = array_shift($fields);

            if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            foreach ($fields as $name) {
                $hostAddresses[strtolower($name)][] = $ip;
            }
        }

        if ($nameservers === []) {
            $nameservers[] = new InetAddress(ip: '127.0.0.1', port: 53);
        }

        return new self(
            nameservers: $nameservers,
            hosts: $hostAddresses,
            search: array_values(array_filter($search, static fn(string $domain) => $domain !== '')),
            ndots: $ndots,
            timeout: $timeout,
            attempts: $attempts,
        );
    }

    /**
     * @param non-empty-list<InetAddress> $nameservers
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $hosts
     * @param list<non-empty-string> $search
     * @param int<0, 15> $ndots
     * @param int<1, 30> $timeout in seconds, as in resolv.conf
     * @param int<1, 5> $attempts
     */
    public function __construct(
        public array $nameservers,
        public array $hosts,
        public array $search,
        public int $ndots,
        public int $timeout,
        public int $attempts,
    ) {}

    /**
     * @return \Generator<list<non-empty-string>> the fields of each line, without comments and empty lines
     */
    private static function lines(string $contents): \Generator
    {
        foreach (explode("\n", $contents) as $line) {
            $line = preg_replace('/[#;].*/', '', $line) ?? '';
            $fields = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY);

            if ($fields !== false && $fields !== []) {
                yield $fields;
            }
        }
    }
}
