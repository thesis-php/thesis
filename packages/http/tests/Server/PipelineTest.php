<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Stub\CallbackMiddleware;
use Thesis\Net\InetAddress;
use function Thesis\Http\getRequest;

#[Test]
final readonly class PipelineTest
{
    private Context $context;

    public function __construct()
    {
        $address = new InetAddress('127.0.0.1', 80);
        $this->context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());
    }

    public function emptyMiddlewareReturnsTheOriginalHandler(): void
    {
        $handler = static fn() => new Response();

        Assert::same($handler, Pipeline::wrap($handler, []));
        Assert::same($handler, Pipeline::wrap($handler, new \EmptyIterator()));
    }

    public function wrapsInOrderAndBindsEachRequest(): void
    {
        $context = $this->context;
        $request = getRequest('/');
        $calls = [];
        $outer = new CallbackMiddleware(static function (Request $incoming, Context $connection, callable $next) use (&$calls) {
            $calls[] = [$incoming, $connection];

            return $next();
        });
        $inner = new CallbackMiddleware(static function (Request $incoming, Context $connection, callable $next) use (&$calls) {
            $calls[] = $connection;

            return $incoming->uri->getRawPath() === '/' ? $next() : $connection->errorHandler->handleError(403, $incoming);
        });
        $endpoint = static function (Request $incoming, Context $connection) use (&$calls) {
            $calls[] = [$incoming, $connection];

            return new Response(201);
        };
        $middleware = static function () use ($outer, $inner) {
            yield 'layer' => $outer;
            yield 'layer' => $inner;
        };
        $handler = Pipeline::wrap($endpoint, $middleware());

        Assert::same(201, $handler($request, $this->context)->status);
        $denied = getRequest('/private');
        Assert::same(403, $handler($denied, $this->context)->status);
        Assert::same([[$request, $context], $context, [$request, $context], [$denied, $context], $context], $calls);
    }

    public function rejectsReentryWhileTheInnerLayerIsRunning(): void
    {
        $endpoint = static fn() => new Response();
        $saved = $endpoint;
        $outer = new CallbackMiddleware(static function (Request $_, Context $context, callable $next) use (&$saved) {
            $saved = $next;

            return $next();
        });
        $inner = new CallbackMiddleware(static function () use (&$saved) {
            return $saved();
        });
        $handler = Pipeline::wrap($endpoint, [$outer, $inner]);

        Expect::exception(\LogicException::class);
        $handler(getRequest('/'), $this->context);
    }

    #[DataSet([false], 'middleware returns')]
    #[DataSet([true], 'middleware throws')]
    public function expiresTheInnerContinuationWhileTheOuterMiddlewareStillRuns(bool $throws): void
    {
        $endpoint = static fn() => new Response();
        $saved = $endpoint;
        $outer = new CallbackMiddleware(static function (Request $_, Context $context, callable $next) use (&$saved) {
            try {
                $next();
            } finally {
                $saved();
            }

            return new Response();
        });
        $inner = new CallbackMiddleware(static function (Request $_, Context $context, callable $next) use (&$saved, $throws) {
            $saved = $next;

            return $throws ? throw new \RuntimeException('Middleware failed.') : new Response();
        });
        $handler = Pipeline::wrap($endpoint, [$outer, $inner]);

        Expect::exception(\LogicException::class)->withMessage('The middleware invocation has ended.');
        $handler(getRequest('/'), $this->context);
    }

    public function repeatsTheSameInnerLayerAfterItReturns(): void
    {
        $twice = new CallbackMiddleware(static function (Request $_, Context $context, callable $next) {
            $next();

            return $next();
        });
        $deny = new CallbackMiddleware(static fn() => new Response(403));
        $handler = Pipeline::wrap(static fn() => new Response(), [$twice, $deny]);

        $response = $handler(getRequest('/'), $this->context);
        Assert::same(403, $response->status);
    }

    public function retriesAfterTheHandlerThrows(): void
    {
        $request = getRequest('/');
        $attempts = [];
        $retry = new CallbackMiddleware(static function (Request $_, Context $context, callable $next) {
            try {
                return $next();
            } catch (\RuntimeException) {
                return $next();
            }
        });
        $endpoint = static function (Request $incoming, Context $connection) use (&$attempts) {
            $attempts[] = [$incoming, $connection];
            if (\count($attempts) === 1) {
                throw new \RuntimeException('Try again.');
            }

            return new Response(201);
        };
        $handler = Pipeline::wrap($endpoint, [$retry]);

        Assert::same(201, $handler($request, $this->context)->status);
        Assert::same([[$request, $this->context], [$request, $this->context]], $attempts);
    }
}
