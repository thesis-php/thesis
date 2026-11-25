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
use Thesis\Http\Server\RouteMatch;
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
                pattern: '/users/{id}',
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

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchAdjacentParameters(int $count): void
    {
        $router = new Router([new Route(
            'GET',
            '/parts/{serial}{code}',
            static fn() => new Response(),
            requirements: ['serial' => '[0-9]+', 'code' => '[a-z]+'],
        )]);
        $serial = str_repeat('1', $count);
        $request = Request::get("/parts/{$serial}ab");
        for ($i = 0; $i < 64; ++$i) {
            $result = $router->match($request);
            if (!$result instanceof RouteMatch || $result->params !== ['serial' => $serial, 'code' => 'ab']) {
                throw new \LogicException('Wrong parameter boundary.');
            }
        }
    }

    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchMixedLookup(int $count): void
    {
        $routes = [];
        $handler = static fn() => new Response();
        for ($i = 0; $i < 100; ++$i) {
            $routes[] = new Route('GET', "/v{$i}/docs/{path*}", $handler);
            $routes[] = new Route(
                'GET',
                "/v{$i}/reports/{year}-{month}",
                $handler,
                requirements: ['year' => '[0-9]{4}'],
            );
            $routes[] = new Route('GET', "/v{$i}/archive/{year?}", $handler);
        }
        $router = new Router($routes);
        $requests = [
            Request::get('/v50/docs/a/b'),
            Request::get('/v60/reports/2026-11'),
            Request::get('/v70/archive'),
            Request::get('/v80/archive/2026'),
        ];
        for ($i = 0; $i < $count; ++$i) {
            foreach ($requests as $request) {
                if (!$router->match($request) instanceof RouteMatch) {
                    throw new \LogicException('Expected a match.');
                }
            }
        }
    }
}
