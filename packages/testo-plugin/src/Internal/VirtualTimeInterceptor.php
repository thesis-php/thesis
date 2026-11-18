<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin\Internal;

use Testo\Common\Messenger;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Log\Level;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;
use Testo\Pipeline\Policy\ConflictPolicy;
use function Thesis\Async\virtualTime;

/**
 * Outer to the lifecycle hooks, which run right before the test, and inner to data providers:
 * the hooks share the test's clock, and every data set starts at zero.
 *
 * Every test gets a seed of its own for the order of what is ready together, or the one of the variable
 * {@see self::SEED} for all: a test that has not passed names its seed, which replays it.
 *
 * One per test: registered by the plugin and by the attribute alike, it is the same class, which conflicts with itself.
 *
 * @internal
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT, onConflict: ConflictPolicy::First)]
final readonly class VirtualTimeInterceptor implements TestRunInterceptor
{
    public const string SEED = 'THESIS_SEED';
    public const string CHANNEL = 'virtual-time';

    public function __construct(
        private Messenger $messenger,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $seed = self::seed();

        try {
            /** @var TestResult */
            $result = virtualTime(static fn(): mixed => $next($info), $seed);
        } catch (\Throwable $error) {
            $this->tell($seed);

            throw $error;
        }

        if (!$result->status->isSuccessful()) {
            $this->tell($seed);
        }

        return $result;
    }

    private static function seed(): int
    {
        $seed = getenv(self::SEED);

        if ($seed === false || $seed === '') {
            return random_int(0, PHP_INT_MAX);
        }

        $value = filter_var($seed, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new \ValueError(\sprintf('%s must be an integer, "%s" given.', self::SEED, $seed));
        }

        return $value;
    }

    private function tell(int $seed): void
    {
        $this->messenger->log(
            channel: self::CHANNEL,
            content: \sprintf('Seed %d: replay with %s=%d.', $seed, self::SEED, $seed),
            level: Level::Warning,
        );
    }
}
