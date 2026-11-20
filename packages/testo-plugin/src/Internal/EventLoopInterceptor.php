<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin\Internal;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;
use Testo\Pipeline\Policy\ConflictPolicy;
use function Thesis\Async\group;

/**
 * The loop owns the fiber of the test, so it is inner to the guards of Testo that keep the state of a test
 * (its assertions, messages, error handlers, coverage): outer to them, it would run the tasks of the test
 * while they have stepped aside. Still outer to the lifecycle hooks, which run right before the test:
 * they can wait too.
 *
 * One per test: registered by the plugin and by the attribute alike, it is the same class, which conflicts with itself.
 *
 * @internal
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_ASYNC_COROUTINE, onConflict: ConflictPolicy::First)]
final readonly class EventLoopInterceptor implements TestRunInterceptor
{
    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        /** @var TestResult */
        return group(static fn(): mixed => $next($info));
    }
}
