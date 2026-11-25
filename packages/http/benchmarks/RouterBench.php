<?php

declare(strict_types=1);

namespace Thesis\Http\Benchmarks;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;
use Thesis\Dispatcher;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\DefaultErrorHandler;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Net\UnixAddress;
use Uri\Rfc3986\Uri;
use function Thesis\Http\Server\routeMatch;

#[Warmup(1)]
final class RouterBench
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        foreach ([128, 512, 2048] as $count) {
            yield "requests-{$count}" => [$count];
        }
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchParameterizedDispatch(int $count): void
    {
        $router = new Router([
            new Route(
                methods: Method::GET,
                route: '/users/:id',
                handler: static fn($request) => new Response(body: routeMatch($request)?->param('id') ?? ''),
            ),
        ]);
        $request = new Request(Method::GET, new Uri('/users/42'));

        $address = new UnixAddress('');
        $context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());

        for ($i = 0; $i < $count; ++$i) {
            if ($router($request, $context)->takeBody()->readAll() !== '42') {
                throw new \LogicException('Wrong parameter.');
            }
        }
    }
}
