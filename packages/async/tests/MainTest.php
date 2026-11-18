<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\inMain;

/**
 * In {main}, where no event loop runs, `group()` runs the loop and its body in a fiber of the loop:
 * the body still sees what the caller has, as it would in the fiber of the caller.
 */
#[Test]
final class MainTest
{
    public function timeoutAroundGroupCancelsItsTasks(): void
    {
        $output = inMain(<<<'PHP'
            try {
                Thesis\Async\timeout(Thesis\Time\ms(50), static fn() => Thesis\Async\race([
                    static fn() => Thesis\Async\delay(Thesis\Time\sec(5)),
                ]));
                echo 'not timed out';
            } catch (Thesis\Async\TimedOut) {
                echo 'timed out';
            }
            PHP);

        Assert::same($output, 'timed out');
    }

    public function groupSeesDeadlineOfCaller(): void
    {
        $output = inMain(<<<'PHP'
            echo Thesis\Async\timeout(Thesis\Time\sec(5), static fn() => Thesis\Async\group(
                static fn() => Thesis\Async\remaining() === null ? 'no deadline' : 'a deadline',
            ));
            PHP);

        Assert::same($output, 'a deadline');
    }

    public function groupSeesScopedValuesOfCaller(): void
    {
        $output = inMain(<<<'PHP'
            $value = new Thesis\Async\ScopedValue('default');
            echo $value->with('bound', static fn() => Thesis\Async\all([$value->get(...)]))[0];
            PHP);

        Assert::same($output, 'bound');
    }

    public function groupBodyReentersLockOfCaller(): void
    {
        $output = inMain(<<<'PHP'
            $mutex = new Thesis\Async\LocalMutex();
            echo Thesis\Async\synchronized($mutex, static fn() => Thesis\Async\group(
                static fn() => Thesis\Async\synchronized($mutex, static fn() => 'reentered'),
            ));
            PHP);

        Assert::same($output, 'reentered');
    }

    public function callbackOfCancelledTaskRunsInRootScopeWithoutValuesOfCaller(): void
    {
        $output = inMain(<<<'PHP'
            $value = new Thesis\Async\ScopedValue('default');
            $value->with('of main', static fn() => Thesis\Async\timeout(Thesis\Time\sec(100), static fn() => Thesis\Async\group(
                static function (Thesis\Async\Group $group) use ($value) {
                    $task = $group->spawn(static function () use ($value) {
                        Thesis\Async\onCancel(static function () use ($value) {
                            echo $value->get(), ', ', Thesis\Async\remaining() === null ? 'no deadline' : 'a deadline';
                        });
                        Thesis\Async\delay(Thesis\Time\sec(5));
                    });
                    Thesis\Async\delay(Thesis\Time\ms(1));
                    $task->cancel();
                },
            )));
            PHP);

        Assert::same($output, 'default, no deadline');
    }
}
