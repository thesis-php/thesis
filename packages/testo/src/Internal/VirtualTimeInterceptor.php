<?php

declare(strict_types=1);

namespace Thesis\Testo\Internal;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;
use Testo\Pipeline\Policy\ConflictPolicy;
use function Thesis\Async\VirtualTime\run;

/**
 * Outer to the lifecycle hooks, which run right before the test, and inner to data providers:
 * the hooks share the test's clock, and every data set starts at zero.
 *
 * One per test: registered by the plugin and by the attribute alike, it is the same class, which conflicts with itself.
 *
 * @internal
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT, onConflict: ConflictPolicy::First)]
final readonly class VirtualTimeInterceptor implements TestRunInterceptor
{
    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        /** @var TestResult */
        return run(static fn(): mixed => $next($info));
    }
}
