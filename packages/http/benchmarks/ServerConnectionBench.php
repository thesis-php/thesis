<?php

declare(strict_types=1);

namespace Thesis\Http\Benchmarks;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;
use Thesis\Http\Headers;
use Thesis\Http\Internal\Server\Connection;
use Thesis\Http\Response;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Stub\RecordingWriter;
use Thesis\Io\StringReader;
use function Thesis\Async\group;

#[Warmup(1)]
final class ServerConnectionBench
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
    public function benchPlaintext(int $count): void
    {
        $input = new StringReader(str_repeat("GET /plaintext HTTP/1.1\r\nHost: localhost\r\n\r\n", $count));
        $output = new RecordingWriter();

        group(static fn() => Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => new Response(
                headers: new Headers(['Content-Type' => 'text/plain']),
                body: 'Hello, World!',
            ),
            config: new Config('/test.sock', maxRequestsPerConnection: $count),
        ));

        if (substr_count($output->bytes, 'Hello, World!') !== $count) {
            throw new \LogicException('The server did not finish every response.');
        }
    }
}
