<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use Thesis\Async\Group;
use Thesis\Async\StreamWatcher;
use function Thesis\Async\group;

/**
 * An echo over a socket pair: every message is a wait for a readable stream on each side.
 */
#[Warmup(1)]
final readonly class StreamWatcherBench
{
    /**
     * @var resource
     */
    private mixed $client;

    /**
     * @var resource
     */
    private mixed $server;

    public function __construct()
    {
        /** @var array{resource, resource} */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        [$this->client, $this->server] = $pair;
        stream_set_blocking($this->client, false);
        stream_set_blocking($this->server, false);
    }

    #[Expected(instructions: '52.83M', cycles: '85.07M', memory: '41.14KiB')]
    public function benchEcho(): void
    {
        $client = $this->client;
        $server = $this->server;

        group(static function (Group $group) use ($client, $server) {
            $group->spawn(static function () use ($server) {
                $watcher = new StreamWatcher($server);

                for ($i = 0; $i < 1000; ++$i) {
                    $watcher->readable();
                    fwrite($server, (string) fread($server, 8192));
                }

                $watcher->close();
            });

            $watcher = new StreamWatcher($client);

            for ($i = 0; $i < 1000; ++$i) {
                fwrite($client, 'ping');
                $watcher->readable();
                fread($client, 8192);
            }

            $watcher->close();
        });
    }
}
