<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Benchmarks;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;
use Thesis\Async\Group;
use Thesis\HttpClient\Client;
use Thesis\Io\Buffer;
use function Thesis\Async\group;
use function Thesis\Net\listen;

#[Warmup(1)]
final class ConnectionBench
{
    /**
     * @return iterable<string, array{positive-int}>
     */
    public static function sizes(): iterable
    {
        foreach ([32, 128, 512] as $count) {
            yield "requests-{$count}" => [$count];
        }
    }

    /**
     * @param positive-int $count
     */
    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchPooledGet(int $count): void
    {
        group(static function (Group $group) use ($count) {
            $server = listen('127.0.0.1', 0);
            $uri = "http://{$server->address}/plaintext";
            $group->spawn(static function () use ($server, $count) {
                $peer = $server->accept() ?? throw new \LogicException();
                $server->close();
                $input = new Buffer();
                for ($i = 0; $i < $count; ++$i) {
                    $input->fillUntil($peer, "\r\n\r\n");
                    $input->drain();
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
                }
                $peer->close();
            });

            $client = new Client();
            for ($i = 0; $i < $count; ++$i) {
                $response = $client->send($uri);
                $body = '';

                while (($bytes = $response->body->read(2)) !== null) {
                    $body .= $bytes;
                }
                if ($response->status !== 200 || $body !== 'ok') {
                    throw new \LogicException('The client did not finish the response.');
                }
            }
        });
    }
}
