<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin\Internal;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;
use Testo\Pipeline\Policy\ConflictPolicy;

/**
 * The handler is set for the whole run of the test: a handler set by the test itself goes above it and sees
 * its errors first. Suppression is error_reporting() & $level, which PHP keeps per fiber.
 *
 * @internal
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT, onConflict: ConflictPolicy::First)]
final readonly class PhpErrorsInterceptor implements TestRunInterceptor
{
    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new \ErrorException($message, severity: $level, filename: $file, line: $line);
        });

        try {
            return $next($info);
        } finally {
            restore_error_handler();
        }
    }
}
