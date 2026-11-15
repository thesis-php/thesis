<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Time\sec;

#[Test]
final class LocalMutexTest
{
    public function criticalSectionsDoNotInterleave(): void
    {
        $mutex = new LocalMutex();
        $log = [];

        group(static function (Group $group) use ($mutex, &$log) {
            foreach (['a', 'b'] as $name) {
                $group->spawn(static function () use ($mutex, $name, &$log) {
                    synchronized($mutex, static function () use ($name, &$log) {
                        $log[] = $name . ' in';
                        delay(sec(1));
                        $log[] = $name . ' out';
                    });
                });
            }
        });

        Assert::same($log, ['a in', 'a out', 'b in', 'b out']);
    }

    public function acquireWaitsUntilHolderReleases(): void
    {
        $mutex = new LocalMutex();

        $log = group(static function (Group $group) use ($mutex) {
            $log = [];
            $lock = $mutex->acquire();
            $group->spawn(static function () use ($lock, &$log) {
                delay(sec(1));
                $log[] = 'released';
                $lock->release();
            });

            $mutex->acquire();
            $log[] = 'acquired';

            return $log;
        });

        Assert::same($log, ['released', 'acquired']);
    }
}
