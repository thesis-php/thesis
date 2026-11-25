<?php

declare(strict_types=1);

namespace Thesis\Http\Benchmarks;

use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Warmup;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Pipeline;
use Thesis\Http\Server\Stub\CallbackMiddleware;
use Thesis\Net\InetAddress;

#[Warmup(1)]
final class ServerPipelineBench
{
    private readonly Request $request;

    private readonly Context $context;

    /**
     * @var \Closure(Request, Context): Response
     */
    // @phpstan-ignore property.uninitialized (Tally calls prepare() before each case.)
    private \Closure $pipeline;

    public function __construct()
    {
        $address = new InetAddress('127.0.0.1', 80);
        $this->context = new Context($address, $address, null);
        $this->request = Request::get('/');
    }

    /**
     * @return iterable<string, array{non-negative-int, bool}>
     */
    public static function chains(): iterable
    {
        foreach ([0, 1, 4, 8] as $count) {
            yield "layers-{$count}" => [$count, false];
        }

        yield 'short-circuit-before-eight' => [8, true];
    }

    /**
     * @param non-negative-int $count
     */
    #[Before]
    public function prepare(int $count, bool $shortCircuit): void
    {
        $response = new Response();
        $handler = static fn() => $response;
        $forward = new CallbackMiddleware(static fn(Request $_, Context $context, callable $next) => $next());
        $middleware = array_fill(0, $count, $forward);
        if ($shortCircuit) {
            array_unshift($middleware, new CallbackMiddleware($handler));
        }

        $this->pipeline = Pipeline::wrap($handler, $middleware)(...);
    }

    /**
     * @param non-negative-int $count
     */
    #[DataProvider('chains')]
    public function benchDispatch(int $count, bool $shortCircuit): void
    {
        ($this->pipeline)($this->request, $this->context);
    }
}
